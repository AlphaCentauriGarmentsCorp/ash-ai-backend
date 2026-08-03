<?php

namespace App\Http\Middleware\Storefront;

use App\Models\Storefront\Customer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional auth: signs the user in if they present a valid token, and shrugs if
 * they do not.
 *
 * This exists for endpoints that are public but say more to someone signed in.
 * Product reviews are the case: anyone may read them, but only a signed-in buyer
 * gets told they can write one. Without this the route would have to be either
 * fully public (and unable to answer "can I review?") or auth-only (locking out the
 * visitors the reviews are mostly for).
 *
 * It must NEVER reject: a bad or absent token simply means "no user".
 */
class ResolveApiUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Customer::findByApiToken(ApiToken::fromRequest($request));

        if ($customer) {
            // Named guard, never a bare auth()->setUser() — that would land the
            // customer on the ERP's default 'web' guard, which is backed by the
            // staff App\Models\User. See AuthenticateApiToken.
            Auth::guard('storefront')->setUser($customer);
            Auth::shouldUse('storefront');

            return $next($request);
        }

        // No token: make sure this request is genuinely anonymous rather than
        // inheriting whoever the guard happens to be holding.
        //
        // Under PHP-FPM every request boots a fresh container so this is a no-op —
        // but under a persistent runtime (Octane, Swoole) the guard survives between
        // requests, and a public endpoint that silently reuses the previous caller's
        // identity is an auth bypass. Cheap to be correct by construction.
        //
        // Scoped to the storefront guard, unlike the standalone app's
        // auth()->forgetGuards(): that resets EVERY guard in the container, which
        // here would also tear down the ERP's own guards mid-request.
        //
        // shouldUse() still runs on this path. Without it the bare auth() helper
        // would fall through to the ERP's 'web' guard, so a member of staff with a
        // live ERP session cookie would read as the "current user" of a public
        // storefront endpoint.
        Auth::guard('storefront')->forgetUser();
        Auth::shouldUse('storefront');

        return $next($request);
    }
}
