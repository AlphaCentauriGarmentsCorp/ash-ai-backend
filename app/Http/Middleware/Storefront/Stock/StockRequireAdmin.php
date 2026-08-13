<?php

namespace App\Http\Middleware\Storefront\Stock;

use App\Models\Storefront\Stock\StockUser;
use App\Support\Storefront\Stock\TokenSessions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only gate for the user-approvals endpoints — port of the Stock manager's
 * ErpRequireAdmin.
 *
 * Note what is missing compared to StockAuthenticate: the service-token branch. That
 * is not an oversight. STOCK_SERVICE_TOKEN exists so a storefront or a sync job can
 * read inventory and post orders; approving warehouse staff, renaming them and
 * revoking their access is a decision a person makes. A machine credential — the kind
 * that ends up in a .env, a CI variable and a teammate's shell history — must not be
 * able to mint itself an admin colleague.
 *
 * The role is read from stock_users on every request rather than from the token, so
 * demoting an admin takes effect on their next call instead of at their next login.
 */
class StockRequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = TokenSessions::fromRequest($request);

        if ($session === null) {
            return response()->json(['error' => 'Not logged in.'], 401);
        }

        $staff = StockUser::activeStaff((string) ($session['username'] ?? ''));

        if ($staff === null) {
            TokenSessions::destroy(TokenSessions::tokenFromRequest($request));

            return response()->json(['error' => 'This session is no longer valid. Please log in again.'], 401);
        }

        if ($staff->role !== 'admin') {
            return response()->json(['error' => 'Admin access required.'], 403);
        }

        $request->attributes->set('authUser', [
            'username' => $staff->username,
            'role' => $staff->role,
            'full_name' => $staff->full_name,
        ]);

        return $next($request);
    }
}
