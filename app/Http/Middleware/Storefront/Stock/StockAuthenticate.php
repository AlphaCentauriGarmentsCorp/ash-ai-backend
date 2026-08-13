<?php

namespace App\Http\Middleware\Storefront\Stock;

use App\Models\Storefront\Stock\StockUser;
use App\Support\Storefront\Stock\TokenSessions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /api/stocks/* — port of the Stock manager's ErpAuthenticate.
 *
 * Two ways in, and only two:
 *
 *  1. A staff Bearer token issued by POST /api/stocks/auth/login and held in the
 *     cache (App\Support\Storefront\Stock\TokenSessions).
 *  2. The fixed machine-to-machine service token from config/stock.php
 *     (STOCK_SERVICE_TOKEN), for a storefront or sync job rather than a person.
 *
 * What it deliberately does NOT do is consult Reefer_Backend's own auth: no guard,
 * no `users` lookup, no api_token. A logged-in shopper is not a warehouse operator,
 * and the shop's 34 customer credentials must not open a single ERP endpoint. The
 * failure shape is the module's own `{"error": "..."}`, not the shop API's
 * `{"message": ...}`, because the Stock frontend reads `data.error`.
 *
 * The session in the cache is only half the check. Every staff request re-reads the
 * account from stock_users, so an admin pressing Deactivate or Reject ends that
 * person's access on their very next call instead of whenever they happen to log
 * out — and the role used downstream is the role they hold *now*, not the one
 * snapshotted into the token at login. The token is minted `forever`; this is what
 * keeps "forever" from meaning "irrevocable".
 */
class StockAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        // --- Machine-to-machine path -------------------------------------
        // hash_equals for a constant-time compare, so the token cannot be guessed a
        // byte at a time by timing the responses. A blank configured token matches
        // nothing: with STOCK_SERVICE_TOKEN unset the module is staff-only, which is
        // the safe default rather than an accidentally open door.
        $bearer = TokenSessions::tokenFromRequest($request);
        $serviceToken = (string) config('stock.service_token');

        if ($serviceToken !== '' && $bearer !== null && hash_equals($serviceToken, $bearer)) {
            $request->attributes->set('authUser', [
                'username' => 'storefront',
                'role' => 'service',
                'full_name' => 'Storefront service',
            ]);

            return $next($request);
        }

        // --- Staff session path -------------------------------------------
        $session = TokenSessions::fromRequest($request);

        if ($session === null) {
            return response()->json(['error' => 'Not logged in.'], 401);
        }

        $staff = StockUser::activeStaff((string) ($session['username'] ?? ''));

        if ($staff === null) {
            // The account was deleted, rejected or deactivated while this token was
            // still in the cache. Burn the token so the next request is a plain
            // "Not logged in." and answer 401 — the frontend's response interceptor
            // treats 401 as "the token I am holding is dead", clears the stored
            // session and sends them to the login screen. A 403 would leave them
            // stuck on a dashboard that fails every call.
            TokenSessions::destroy($bearer);

            return response()->json(['error' => 'This session is no longer valid. Please log in again.'], 401);
        }

        $request->attributes->set('authUser', [
            'username' => $staff->username,
            'role' => $staff->role,
            'full_name' => $staff->full_name,
        ]);

        return $next($request);
    }
}
