<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quotation\DesignReviewUpdate;
use App\Models\Quotation;
use App\Services\NotificationService;
use App\Services\QuotationService;
use Illuminate\Support\Facades\Auth;

/**
 * Issue 8 (Sec. 5) — Graphic Artist design-review surface.
 *
 * This is the GA's least-privilege window into a quotation. It is mounted
 * under `permission:access.quotation-review` (held by graphic_artist +
 * superadmin) — deliberately OUTSIDE the access.quotations group, so a GA can
 * review a design without being granted full quotation CRUD.
 *
 * It exposes only what the GA needs to judge colours/clarity (specs + design
 * images + current verdict), never the full client/pricing record.
 */
class QuotationReviewController extends Controller
{
    public function __construct(
        protected NotificationService $notifications,
        protected QuotationService $quotations,
    ) {
    }

    /**
     * GA-scoped view of a single quotation under review.
     */
    public function show($id)
    {
        $quotation = Quotation::with('designReviewer')->findOrFail($id);

        return response()->json([
            'data' => $this->reviewPayload($quotation),
        ]);
    }

    /**
     * Record the GA's verdict (GA Approved / Needs New File), the verified
     * colour count, and an optional note to the CSR.
     */
    public function update(DesignReviewUpdate $request, $id)
    {
        $quotation = Quotation::with('designReviewer')->findOrFail($id);
        $validated = $request->validated();

        $quotation->design_review_status = $validated['design_review_status'];
        $quotation->design_color_count   = $validated['design_color_count'] ?? $quotation->design_color_count;
        $quotation->design_review_note   = $validated['design_review_note'] ?? null;
        $quotation->design_reviewed_by   = Auth::id();
        $quotation->design_reviewed_at   = now();
        $quotation->save();

        // Per-side override (artist front/back): when the GA supplies a colour
        // count per placement, write each onto its print_parts row so the
        // per-placement pricing engine charges each side on its own. The single
        // design_color_count is kept in sync (sum) for display, the single-
        // placement path, and the recompute gate below.
        $perSide = $validated['design_color_counts'] ?? null;
        if (is_array($perSide) && count($perSide) > 0) {
            $parts = is_array($quotation->print_parts_json) ? $quotation->print_parts_json : [];
            foreach ($perSide as $entry) {
                $i = (int) ($entry['index'] ?? -1);
                if ($i >= 0 && isset($parts[$i]) && is_array($parts[$i])) {
                    $colors = (int) ($entry['num_colors'] ?? 0);
                    $parts[$i]['num_colors']  = $colors; // authoritative for pricing
                    $parts[$i]['color_count'] = $colors; // keep display in sync
                }
            }
            $quotation->print_parts_json = array_values($parts);
            $quotation->design_color_count = array_sum(array_map(
                static fn ($e) => (int) ($e['num_colors'] ?? 0),
                $perSide
            ));
            $quotation->save();
        }

        // ── Stage D — pricing recompute ──────────────────────────────────
        // Only an APPROVED design with a verified colour count drives pricing
        // (a "Needs New File" verdict means the colours are not final). When it
        // applies, feed the count into the (silkscreen) pricing engine, which
        // recomputes the grand total + 60/40 split and regenerates the PDF.
        // No-op for non-silkscreen methods. Best-effort: a recompute failure
        // must not lose the saved verdict.
        $approvedWithColors = $quotation->design_review_status === Quotation::DESIGN_REVIEW_APPROVED
            && $quotation->design_color_count !== null;

        if ($approvedWithColors) {
            try {
                $this->quotations->recomputeForDesignColorCount($quotation->id);
                $quotation = $quotation->fresh('designReviewer');
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Notify the CSR of the result (best-effort).
        try {
            $this->notifications->designReviewDecided($quotation, $quotation->design_review_status);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'message' => 'Design review saved.',
            'data' => $this->reviewPayload($quotation->fresh('designReviewer')),
        ]);
    }

    /**
     * The lean, GA-facing shape. Design images come from print_parts_json,
     * which now carries each part's stored image path (Issue 8 upload fix).
     */
    protected function reviewPayload(Quotation $quotation): array
    {
        $printParts = is_array($quotation->print_parts_json) ? $quotation->print_parts_json : [];

        // First available part image → convenience thumbnail for the header.
        $thumbnail = null;
        foreach ($printParts as $part) {
            $candidate = $part['image_link'] ?? $part['image_path'] ?? $part['image'] ?? null;
            if (! empty($candidate)) {
                $thumbnail = $candidate;
                break;
            }
        }

        return [
            'id' => $quotation->id,
            'quotation_id' => $quotation->quotation_id,
            'client_name' => $quotation->client_name,
            // Spec context the GA needs to judge colours/clarity.
            'print_method_id' => $quotation->print_method_id,
            'print_area' => $quotation->print_area,
            'special_print' => $quotation->special_print,
            'shirt_color' => $quotation->shirt_color,
            'notes' => $quotation->notes,
            'print_parts' => $printParts,
            'design_thumbnail' => $thumbnail,
            // Current review state.
            'design_review_status' => $quotation->design_review_status,
            'design_color_count' => $quotation->design_color_count,
            'design_review_note' => $quotation->design_review_note,
            'design_reviewed_at' => $quotation->design_reviewed_at,
            'design_reviewer' => $quotation->designReviewer?->name,
            'allowed_verdicts' => [
                Quotation::DESIGN_REVIEW_APPROVED,
                Quotation::DESIGN_REVIEW_NEEDS_FILE,
            ],
        ];
    }
}
