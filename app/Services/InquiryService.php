<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * InquiryService — pre-quotation lead lifecycle.
 *
 * Owns the inquiry → quotation conversion bridge. The conversion
 * itself spawns a Draft quotation via QuotationService::createDraft()
 * (no email, no PDF) and back-references the new quotation on the
 * inquiry row.
 *
 * Idempotency: once an inquiry is `converted`, re-calling
 * convertToQuotation() returns 409 instead of creating a duplicate
 * quotation.
 */
class InquiryService
{
    public function __construct(
        protected PoCodeGenerator   $poCodeGenerator,
        protected QuotationService  $quotationService,
        protected CsrActivityLogger $logger,
    ) {}

    /**
     * List inquiries with optional filters.
     *
     * @param array{status?: string, assigned_csr_user_id?: int, search?: string} $filters
     */
    public function list(array $filters = []): Collection
    {
        $q = Inquiry::with(['client', 'assignedCsr', 'quotation']);

        if (!empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (!empty($filters['assigned_csr_user_id'])) {
            $q->where('assigned_csr_user_id', $filters['assigned_csr_user_id']);
        }
        if (!empty($filters['search'])) {
            $needle = '%' . $filters['search'] . '%';
            $q->where(function ($w) use ($needle) {
                $w->where('client_name', 'LIKE', $needle)
                  ->orWhere('brand_name',   'LIKE', $needle)
                  ->orWhere('inquiry_code', 'LIKE', $needle);
            });
        }

        return $q->orderByDesc('created_at')->get();
    }

    public function find(int $id): ?Inquiry
    {
        return Inquiry::with(['client', 'assignedCsr', 'quotation'])->find($id);
    }

    /**
     * Create a new inquiry. `inquiry_code` is auto-generated.
     */
    public function create(array $data): Inquiry
    {
        return DB::transaction(function () use ($data) {
            $data['inquiry_code']  = $this->poCodeGenerator->generate('INQ');
            $data['status']        = $data['status'] ?? Inquiry::STATUS_NEW;

            $inquiry = Inquiry::create($data);

            $this->logger->log(
                action: 'inquiry.created',
                summary: $inquiry->inquiry_code . ' — ' . $inquiry->client_name,
                subject: $inquiry,
                clientId: $inquiry->client_id,
            );

            return $inquiry->fresh(['client', 'assignedCsr', 'quotation']);
        });
    }

    public function update(int $id, array $data): Inquiry
    {
        return DB::transaction(function () use ($id, $data) {
            /** @var Inquiry $inquiry */
            $inquiry = Inquiry::lockForUpdate()->findOrFail($id);

            // Don't allow code or back-ref to be overwritten through update
            unset($data['inquiry_code'], $data['quotation_id']);

            $inquiry->fill($data)->save();

            return $inquiry->fresh(['client', 'assignedCsr', 'quotation']);
        });
    }

    /**
     * Convert an inquiry to a draft quotation.
     *
     * Locked decisions:
     *   C13 — "Convert to Quotation" button pre-fills via this method
     *   C14 — calls QuotationService::createDraft($payload)
     *   C15 — created quotation has status='Draft'
     *   C16 — frontend redirects to /quotations/{id}/edit after this
     *
     * Idempotency: if inquiry.status === 'converted', throw a
     * ValidationException with status code 409. The caller (controller)
     * is expected to translate this to an HTTP 409 response that
     * includes the existing quotation_id so the frontend can redirect
     * to it directly.
     *
     * @throws ValidationException with code 409 when already converted
     */
    public function convertToQuotation(int $inquiryId): array
    {
        return DB::transaction(function () use ($inquiryId) {
            /** @var Inquiry $inquiry */
            $inquiry = Inquiry::lockForUpdate()->findOrFail($inquiryId);

            // Idempotency guard — already converted, return 409
            if ($inquiry->status === Inquiry::STATUS_CONVERTED) {
                throw ValidationException::withMessages([
                    'inquiry' => [
                        "Inquiry {$inquiry->inquiry_code} is already converted to quotation id={$inquiry->quotation_id}.",
                    ],
                ])->status(409);
            }

            // Build the draft payload — name maps documented in the
            // Phase 6-A handoff §4 "Conversion-to-quotation mapping".
            $notes = "From inquiry {$inquiry->inquiry_code}\n";
            if (!empty($inquiry->product_interest)) {
                $notes .= "Product: {$inquiry->product_interest}\n";
            }
            if (!empty($inquiry->internal_notes)) {
                $notes .= "Internal: {$inquiry->internal_notes}";
            }

            $payload = [
                'user_id'          => Auth::id(),
                'client_id'        => $inquiry->client_id,
                'client_name'      => $inquiry->client_name,
                'client_email'     => $inquiry->client_email,
                'client_facebook'  => $inquiry->facebook_link,
                'client_brand'     => $inquiry->brand_name,
                'notes'            => $notes,
                'item_config_json' => [],
                'items_json'       => [],
                'addons_json'      => [],
                'subtotal'         => 0,
                'grand_total'      => 0,
                'status'           => Quotation::STATUS_DRAFT,
            ];

            /** @var Quotation $quotation */
            $quotation = $this->quotationService->createDraft($payload);

            // Back-reference + status flip on the source inquiry
            $inquiry->update([
                'status'       => Inquiry::STATUS_CONVERTED,
                'quotation_id' => $quotation->id,
            ]);

            // Audit
            $this->logger->log(
                action: 'inquiry.converted_to_quotation',
                summary: "{$inquiry->inquiry_code} → {$quotation->quotation_id}",
                subject: $inquiry,
                clientId: $inquiry->client_id,
                data: [
                    'quotation_id'  => $quotation->id,
                    'quotation_code' => $quotation->quotation_id,
                ],
            );

            return [
                'inquiry'   => $inquiry->fresh(['client', 'assignedCsr', 'quotation']),
                'quotation' => $quotation,
            ];
        });
    }
}
