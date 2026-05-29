<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QuotationService;
use App\Http\Requests\Quotation\Store;
use App\Http\Requests\Quotation\Update;
use App\Http\Requests\Quotation\StatusUpdate;
use App\Http\Resources\QuotationResource;
use App\Models\Quotation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;

class QuotationController extends Controller
{
    protected $service;

    public function __construct(QuotationService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $printTypes = $this->service->getAll();
        return QuotationResource::collection($printTypes);
    }
public function store(Store $request)
{
    $validated = $request->validated();

    // Issue 8 — print-part design files are stored AND linked to their part
    // by QuotationService::handlePrintParts (keyed by part index). The earlier
    // collectUploadedPrintParts() call here stored the files a second time into
    // a non-fillable 'print_parts_files' key that was silently dropped, leaving
    // orphaned duplicates on disk. Removed so the service is the single owner.

    // Resolve the custom-pattern reference (Issue 6): an uploaded image file
    // OR an external link. Stored as a single string on the quotation.
    $validated['custom_pattern_image'] = $this->resolveCustomPatternImage($request);

    // Resolve the shared label-design artwork (Issue 7): an uploaded file
    // OR an external link. One upload covers both Brand + Care/Size labels.
    $resolvedLabelDesign = $this->resolveLabelDesign($request);
    if ($resolvedLabelDesign !== null) {
        $validated['label_design_path'] = $resolvedLabelDesign;
    }

    // Create the quotation using the service layer
    $quotation = $this->service->store($validated, $request);

    // Return the response with the newly created quotation
    return (new QuotationResource($quotation))->response()->setStatusCode(201);
}

    /**
     * Live price preview. Computes totals from the current form state without
     * saving. Same pricing engine as store(), so preview == saved quote.
     * Accepts a plain JSON body (no file uploads needed for a preview).
     */
    public function preview(\Illuminate\Http\Request $request)
    {
        $totals = $this->service->preview($request->all());

        return response()->json($totals);
    }

    public function update(Update $request, $id)
    {
        $validated = $request->validated();

        // Issue 8 — file storage + part association handled by the service
        // (see store()). No separate collect/store here.

        // Resolve the custom-pattern reference (Issue 6). Only override when the
        // request actually carries one, so an edit that doesn't touch it keeps
        // the existing value (handled in the service via the existing record).
        $resolvedCustomPattern = $this->resolveCustomPatternImage($request);
        if ($resolvedCustomPattern !== null) {
            $validated['custom_pattern_image'] = $resolvedCustomPattern;
        }

        // Resolve the shared label-design artwork (Issue 7). Only override when
        // the request actually carries one, so an edit that doesn't touch it
        // keeps the existing value (handled in the service via the existing
        // record).
        $resolvedLabelDesign = $this->resolveLabelDesign($request);
        if ($resolvedLabelDesign !== null) {
            $validated['label_design_path'] = $resolvedLabelDesign;
        }

        // Update the quotation with new data
        $quotation = $this->service->update($validated, $id, $request);

        return new QuotationResource($quotation);
    }

    /**
     * Resolve the custom-pattern reference image (Issue 6) from the request.
     * Accepts either an uploaded file (`custom_pattern_image_file`) which is
     * stored to public disk, or a text link/path (`custom_pattern_image`).
     * Returns the resolved string, or null when the request carries neither
     * (so callers can decide whether to leave an existing value untouched).
     */
    protected function resolveCustomPatternImage(\Illuminate\Http\Request $request): ?string
    {
        if ($request->hasFile('custom_pattern_image_file')) {
            $file = $request->file('custom_pattern_image_file');
            if ($file && $file->isValid()) {
                return $file->store('quotation-custom-patterns', 'public');
            }
        }

        $link = $request->input('custom_pattern_image');
        if (is_string($link) && trim($link) !== '') {
            return trim($link);
        }

        return null;
    }

    /**
     * Resolve the shared label-design artwork (Issue 7) from the request.
     * Accepts either an uploaded file (`label_design_file`) which is stored
     * to public disk, or a text link/path (`label_design_path`). One upload
     * is shared between the Brand Label and the Care/Size Label. Returns the
     * resolved string, or null when the request carries neither (so callers
     * can leave an existing value untouched on edit).
     */
    protected function resolveLabelDesign(\Illuminate\Http\Request $request): ?string
    {
        if ($request->hasFile('label_design_file')) {
            $file = $request->file('label_design_file');
            if ($file && $file->isValid()) {
                return $file->store('quotation-label-designs', 'public');
            }
        }

        $link = $request->input('label_design_path');
        if (is_string($link) && trim($link) !== '') {
            return trim($link);
        }

        return null;
    }

    /**
     * Walk the uploaded `print_parts_files` payload and return an array of
     * stored file paths, regardless of whether the frontend sent the files
     * flat (`print_parts_files[0] = file`) or nested
     * (`print_parts_files[0][0] = file`). This way the controller stays
     * robust against either FormData shape.
     */
    protected function collectUploadedPrintParts(\Illuminate\Http\Request $request): array
    {
        $raw = $request->file('print_parts_files');
        if (empty($raw) || ! is_array($raw)) {
            return [];
        }

        $stored = [];

        foreach ($raw as $item) {
            // Flat shape: $item is an UploadedFile.
            if ($item instanceof \Illuminate\Http\UploadedFile) {
                if ($item->isValid()) {
                    $stored[] = $item->store('quotation-print-parts', 'public');
                }
                continue;
            }

            // Nested shape: $item is itself an array of UploadedFile objects.
            if (is_array($item)) {
                foreach ($item as $subItem) {
                    if ($subItem instanceof \Illuminate\Http\UploadedFile && $subItem->isValid()) {
                        $stored[] = $subItem->store('quotation-print-parts', 'public');
                    }
                }
            }
        }

        return $stored;
    }


    public function show($id)
    {
        $quotation = $this->service->find($id);
        if (!$quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }
        return new QuotationResource($quotation);
    }

    public function generatePDF($id)
    {
        $quotation = Quotation::findOrFail($id);

        $fileName = $quotation->quotation_id . '.pdf';
        $filePath = "quotations/{$fileName}";

        // Issue 12 — regenerate on demand if the file is missing (e.g. storage
        // was cleared, or the row predates PDF generation). The PDF is normally
        // written on save, so this is a fallback, not the common path.
        if (!Storage::disk('public')->exists($filePath)) {
            try {
                $pdf = Pdf::loadView('pdf', ['quotation' => $quotation]);
                Storage::disk('public')->put($filePath, $pdf->output());
                if ($quotation->pdf_path !== $filePath) {
                    $quotation->update(['pdf_path' => $filePath]);
                }
            } catch (\Throwable $e) {
                return response()->json(['message' => 'PDF could not be generated.'], 500);
            }
        }

        // Get the full path in storage
        $fullPath = Storage::disk('public')->path($filePath);

        return response()->file($fullPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"$fileName\"",
        ]);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        return response()->json(['message' => 'Quotation deleted successfully']);
    }

    /**
     * Mark a quotation as Converted and return the prefill payload that
     * the frontend can use to populate /orders/new.
     *
     * Returns 409 if the quotation is already Converted.
     */
    public function confirm($id)
    {
        $result = $this->service->confirmAndConvert((int) $id);

        return response()->json($result);
    }

    /**
     * Issue 12 — change a quotation's status through the lifecycle state
     * machine (Draft/Pending → Sent → Approved → Converted; Rejected reopenable;
     * Expired terminal). The "Sent" transition also emails the PDF to the client.
     *
     * Body: { status: <target>, notes?: <string> }
     * Returns 422 on an illegal/unknown transition (validation error shape).
     */
    public function changeStatus(StatusUpdate $request, $id)
    {
        $validated = $request->validated();

        $result = $this->service->changeStatus(
            (int) $id,
            $validated['status'],
            $validated['notes'] ?? null
        );

        return response()->json($result);
    }

    /**
     * Issue 12 — return the immutable status-transition history for a
     * quotation (newest first), with the acting user's name resolved.
     */
    public function statusLog($id)
    {
        $quotation = Quotation::with(['statusLogs.user:id,name'])->find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        $log = $quotation->statusLogs->map(function ($row) {
            return [
                'id'          => $row->id,
                'from_status' => $row->from_status,
                'to_status'   => $row->to_status,
                'notes'       => $row->notes,
                'email_sent'  => $row->email_sent,
                'user'        => $row->user?->name,
                'created_at'  => optional($row->created_at)->toIso8601String(),
            ];
        });

        return response()->json(['data' => $log]);
    }

    /**
     * Issue 8 — the CSR sends a quotation to the Graphic Artist for a
     * colours/clarity review. Sets the design-review status to "Pending GA"
     * and notifies the GA role (their entry point — there is no GA queue).
     * Re-callable: a "Needs New File" quotation can be re-sent after the CSR
     * uploads a new design.
     */
    public function requestDesignReview($id, \App\Services\NotificationService $notifications)
    {
        $quotation = Quotation::find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        $quotation->design_review_status = Quotation::DESIGN_REVIEW_PENDING;
        // Clear the stale reviewer/verdict so the GA starts fresh.
        $quotation->design_reviewed_by = null;
        $quotation->design_reviewed_at = null;
        $quotation->save();

        try {
            $notifications->designReviewRequested($quotation);
        } catch (\Throwable $e) {
            report($e);
        }

        return new QuotationResource($quotation->fresh(['user', 'designReviewer']));
    }
}