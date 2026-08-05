<?php

namespace App\Http\Resources\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Storefront\ProductReview
 */
class ProductReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'body' => $this->body,
            'date' => optional($this->created_at)->format('M j, Y'),

            // Reviews are public, so this goes out to strangers. Only a display name
            // — never the email, and never the user id, which would let anyone map
            // reviews to accounts.
            'author' => $this->displayName(),

            // Every review is by definition from a verified buyer: the write path
            // refuses anyone who has not purchased the product.
            'verified_purchase' => true,

            // Lets the page mark "your review" without exposing who wrote the others.
            'mine' => $request->user() !== null && $this->user_id === $request->user()->id,
        ];
    }

    /**
     * "Juan dela Cruz" -> "Juan D." — enough to feel like a person, not enough to
     * identify one from a public page.
     */
    private function displayName(): string
    {
        $name = trim((string) ($this->user?->name ?? ''));

        if ($name === '') {
            return 'Reefer customer';
        }

        $parts = preg_split('/\s+/u', $name);
        $first = array_shift($parts);

        if (! $parts) {
            return $first;
        }

        /*
         * mb_*, not substr()/strtoupper(). substr($surname, 0, 1) takes the first BYTE.
         * "Peña" starts fine, but a surname BEGINNING with a multi-byte character —
         * Ñoño, Ángeles, an emoji — is 2+ bytes there, so taking one byte returned half
         * a character. That is not valid UTF-8, and json_encode refuses to encode it:
         * response()->json() threw "Malformed UTF-8 characters", so the whole reviews
         * endpoint answered 500 for every product that customer had reviewed. Not an
         * edge case here — Ñ is ordinary in Filipino surnames.
         *
         * The /u on the split above matters for the same reason: without it \s+ can
         * split inside a multi-byte sequence.
         */
        $initial = mb_substr(end($parts), 0, 1, 'UTF-8');

        return $first.' '.mb_strtoupper($initial, 'UTF-8').'.';
    }
}
