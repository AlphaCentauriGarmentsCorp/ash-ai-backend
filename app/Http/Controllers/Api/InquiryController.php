<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Csr\StoreInquiry;
use App\Http\Requests\Csr\UpdateInquiry;
use App\Models\Inquiry;
use App\Services\InquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InquiryController extends Controller
{
    public function __construct(
        protected InquiryService $inquiries,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $list = $this->inquiries->list([
            'status'               => $request->string('status')->value() ?: null,
            'assigned_csr_user_id' => $request->integer('assigned_csr_user_id') ?: null,
            'search'               => $request->string('search')->value() ?: null,
        ]);

        return response()->json([
            'data' => $list->map(fn (Inquiry $i) => $this->present($i))->all(),
        ]);
    }

    public function store(StoreInquiry $request): JsonResponse
    {
        $inquiry = $this->inquiries->create($request->validated());

        return response()->json([
            'data' => $this->present($inquiry),
        ], 201);
    }

    public function update(UpdateInquiry $request, int $id): JsonResponse
    {
        $inquiry = $this->inquiries->update($id, $request->validated());

        return response()->json([
            'data' => $this->present($inquiry),
        ]);
    }

    /**
     * POST /csr/inquiries/{id}/convert-to-quotation
     *
     * Returns 200 with both the updated inquiry and the new quotation
     * on success. Returns 409 with the existing quotation_id when the
     * inquiry was already converted (idempotency).
     */
    public function convertToQuotation(int $id): JsonResponse
    {
        try {
            $result = $this->inquiries->convertToQuotation($id);
        } catch (ValidationException $e) {
            $status = $e->status ?: 422;

            if ($status === 409) {
                // Pull the existing quotation_id from the inquiry for the response
                $existing = Inquiry::find($id);
                return response()->json([
                    'message'        => $e->getMessage() ?: 'Inquiry already converted.',
                    'errors'         => $e->errors(),
                    'quotation_id'   => $existing?->quotation_id,
                ], 409);
            }

            throw $e;
        }

        return response()->json([
            'data' => [
                'inquiry'   => $this->present($result['inquiry']),
                'quotation' => [
                    'id'           => $result['quotation']->id,
                    'quotation_id' => $result['quotation']->quotation_id,
                    'status'       => $result['quotation']->status,
                ],
            ],
        ]);
    }

    /**
     * Build the presenter shape for an Inquiry.
     */
    protected function present(Inquiry $i): array
    {
        return [
            'id'                   => $i->id,
            'inquiry_code'         => $i->inquiry_code,
            'client_id'            => $i->client_id,
            'client_name'          => $i->client_name,
            'client_email'         => $i->client_email,
            'client_contact'       => $i->client_contact,
            'brand_name'           => $i->brand_name,
            'source'               => $i->source,
            'messenger_link'       => $i->messenger_link,
            'facebook_link'        => $i->facebook_link,
            'gc_link'              => $i->gc_link,
            'product_interest'     => $i->product_interest,
            'status'               => $i->status,
            'assigned_csr_user_id' => $i->assigned_csr_user_id,
            'quotation_id'         => $i->quotation_id,
            'internal_notes'       => $i->internal_notes,
            'created_at'           => $i->created_at?->toIso8601String(),
            'updated_at'           => $i->updated_at?->toIso8601String(),
        ];
    }
}
