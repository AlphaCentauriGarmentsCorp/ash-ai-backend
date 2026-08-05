<?php

namespace App\Http\Middleware\Storefront;

use App\Models\Storefront\Customer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a signed-in account. Compare with ResolveApiUser, which tolerates one.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Customer::findByApiToken(ApiToken::fromRequest($request));

        if (! $customer) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $customer->forceFill(['api_token_last_used_at' => now()])->saveQuietly();

        // Named guard, never a bare auth()->setUser(). This app also hosts the ERP,
        // whose default 'web' guard is backed by the staff App\Models\User — putting
        // a Customer on it would hand storefront identity to ERP code (and vice
        // versa). shouldUse() then points the bare auth() helper at the storefront
        // guard for the rest of the request, so auth()->id() inside the storefront
        // controllers resolves THIS customer while the ERP's guards are left alone.
        Auth::guard('storefront')->setUser($customer);
        Auth::shouldUse('storefront');

        return $next($request);
    }
}
