<?php

namespace App\Http\Requests\Quotation;

use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Issue 8 — validate a Graphic Artist design-review verdict.
 *
 * Authorization (which roles may hit this) is enforced by the
 * `permission:access.quotation-review` middleware on the route, so this
 * request only validates the payload shape.
 */
class DesignReviewUpdate extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // GA Approved / Needs New File. (Pending GA is set by the CSR's
            // "send to GA" action, not by the GA's verdict.)
            'design_review_status' => [
                'required',
                'string',
                Rule::in([
                    Quotation::DESIGN_REVIEW_APPROVED,
                    Quotation::DESIGN_REVIEW_NEEDS_FILE,
                ]),
            ],
            // Pooled colour count the GA verified. Optional — only meaningful
            // for colour-based methods (silkscreen). Feeds pricing in stage D.
            'design_color_count' => ['nullable', 'integer', 'min:0', 'max:99'],
            // Per-placement colour counts the GA verified (front/back/etc.),
            // each tied to a print_parts row by its index. Overrides the CSR's
            // per-placement counts for silkscreen pricing; when present it
            // supersedes the single pooled design_color_count.
            'design_color_counts' => ['nullable', 'array', 'max:50'],
            'design_color_counts.*.index' => ['required_with:design_color_counts', 'integer', 'min:0'],
            'design_color_counts.*.num_colors' => ['required_with:design_color_counts', 'integer', 'min:0', 'max:99'],
            'design_review_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
