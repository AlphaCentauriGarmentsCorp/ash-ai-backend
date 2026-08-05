<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Storefront\DiscountService;
use App\Services\Storefront\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Voucher preview for the checkout page.
 *
 * A quote, never a promise: the order endpoint resolves and re-prices the code
 * again against its own subtotal, so nothing here reserves a use. Refusals come
 * back as a 422 whose message is already written for the shopper.
 */
class DiscountController extends Controller
{
    public function __construct(
        private readonly DiscountService $discounts,
        private readonly PricingService $pricingService,
    ) {
    }

    /** POST /discounts/validate {code, shipping_method?} */
    public function validateCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            // Optional. Left out, the quote is merchandise only — the checkout page
            // already knows its own shipping line and adds it back.
            'shipping_method' => ['nullable', Rule::in(array_keys(config('reefer.shipping_methods')))],
        ]);

        $user = $request->user();

        // Priced from the lines this account has ticked for checkout, exactly as
        // CartResource derives selected_subtotal. A client-sent subtotal would be a
        // client-sent discount.
        $subtotal = $user->currentCart()->loadLines()->selectedSubtotal();

        if ($subtotal <= 0) {
            throw ValidationException::withMessages([
                'code' => 'Tick the items you are buying before applying a code.',
            ]);
        }

        $discount = $this->discounts->preview($data['code'], $user, $subtotal);
        $quote = $this->pricingService->quote(
            $subtotal,
            $data['shipping_method'] ?? null,
            $discount->discountFor($subtotal),
        );

        return response()->json(['data' => [
            // The canonical uppercase code, which is what the order should carry
            // back — not the casing they happened to type.
            'code' => $discount->code,
            'type' => $discount->type,
            'value' => $discount->value,
            'description' => $discount->description,

            'subtotal' => $quote['subtotal'],
            'discount_amount' => $quote['discount'],
            'discount_amount_formatted' => '₱'.number_format($quote['discount']),
            'shipping_fee' => $quote['shipping_fee'],
            'total_preview' => $quote['total'],
            'total_preview_formatted' => '₱'.number_format($quote['total']),
        ]]);
    }
}
