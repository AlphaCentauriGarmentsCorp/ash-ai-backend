<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\UpdateAccountRequest;
use App\Http\Resources\Storefront\AddressResource;
use App\Http\Resources\Storefront\CustomerResource;
use App\Http\Resources\Storefront\OrderResource;
use Illuminate\Http\JsonResponse;

class AccountController extends Controller
{
    /**
     * Everything the My Account page needs in one call. Orders are capped rather
     * than paginated — the page only renders the most recent handful, and the full
     * history is what GET /orders is for.
     */
    public function dashboard(): JsonResponse
    {
        $user = auth()->user();

        $orders = $user->orders()
            ->with('items')
            ->latest('placed_at')
            ->latest('id')
            ->limit(20)
            ->get();

        return response()->json([
            'user' => new CustomerResource($user),
            'addresses' => AddressResource::collection(
                $user->addresses()->orderByDesc('is_default_shipping')->orderBy('id')->get()
            ),
            'orders' => OrderResource::collection($orders),
        ]);
    }

    /**
     * PATCH /account — partial update. Send only the fields that changed.
     */
    public function update(UpdateAccountRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        // current_password is proof of identity, not a column.
        unset($data['current_password']);

        // A verified flag belongs to the address that was verified, not to the account.
        // Carrying it across an email change would let anyone verify a throwaway inbox
        // and then swap in an address they do not own, arriving pre-verified.
        // forceFill only for these three — they are ours to set, never the client's.
        if (array_key_exists('email', $data) && $data['email'] !== $user->email) {
            $user->forceFill([
                'email_verified_at' => null,
                'email_verification_code' => null,
                'email_verification_sent_at' => null,
            ]);
        }

        $user->fill($data)->save();

        // Changing a credential invalidates other sessions: the caller gets a fresh
        // token, and anyone holding the old one is logged out.
        $token = null;
        if ($request->has('password') || $request->has('email')) {
            $token = $user->issueApiToken();
        }

        return response()->json(array_filter([
            'message' => 'Account updated.',
            'user' => new CustomerResource($user->fresh()),
            'token' => $token,
        ], fn ($v) => $v !== null));
    }
}
