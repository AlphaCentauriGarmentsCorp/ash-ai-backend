<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Public quotation resource.
 *
 * This now returns the full quotation payload instead of a redacted subset.
 */
class PublicQuotationResource extends QuotationResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
