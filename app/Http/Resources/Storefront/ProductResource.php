<?php

namespace App\Http\Resources\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->slug,
            'slug' => $this->slug,
            'name' => $this->name,
            'audience' => $this->audience,
            'type' => $this->type,
            'price' => $this->price,
            'price_formatted' => '₱' . number_format($this->price),
            'tag' => $this->tag,
            'blurb' => $this->blurb,
            'material' => $this->material,
            'fit_name' => $this->fit_name,
            'fit_desc' => $this->fit_desc,
            // These three read $this->variants, so they are gated on the relation
            // actually being loaded — otherwise a caller who forgets with('variants')
            // silently pays a query per product instead of getting an error.
            'sizes' => $this->whenLoaded('variants', fn () => $this->sizes),
            'placeholder' => $this->name ? 'Drop the ' . $this->name . ' shot' : null,
            'low_stock' => $this->whenLoaded('variants', fn () => $this->low_stock),
            // Three states, not two. low_stock is false both when there is plenty
            // and when there is nothing at all — reading it as a bare boolean put
            // an "IN STOCK" badge on products whose every size was struck through,
            // which is what a brand-new design with no stock looks like.
            'stock_label' => $this->whenLoaded('variants', function () {
                if ($this->total_stock <= 0) {
                    return 'SOLD OUT';
                }

                return $this->low_stock ? 'ONLY A FEW LEFT' : 'IN STOCK';
            }),
            'image' => $this->image_path ? asset('storage/' . $this->image_path) : null,

            // Ratings are public, so they ride along with the catalog and every
            // shopper sees them signed in or not.
            //
            // whenCounted/whenAggregated, so a caller who forgets
            // withCount('reviews')->withAvg('reviews', 'rating') gets nothing rather
            // than a silent query per product. The full review list and the
            // can-I-review answer live on /products/{slug}/reviews — this is only
            // enough to draw stars on a card.
            'rating_count' => $this->whenCounted('reviews'),
            'rating_average' => $this->whenAggregated('reviews', 'rating', 'avg', fn () => round((float) $this->reviews_avg_rating, 1)),

            // Per-size detail the storefront is allowed to show. Warehouse, shelf,
            // area, on_hand and allocated stay out of it — those are the ERP's
            // business, and publishing them tells strangers how the stockroom is laid
            // out and exactly how well each size is selling.
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($v) => [
                'size' => $v->size,
                'sku' => $v->sku,
                'stock' => $v->available,
                'weight_grams' => $v->weight_grams !== null ? (float) $v->weight_grams : null,
                'dimensions' => $v->dimensions,
            ])->values()),
        ];
    }
}
