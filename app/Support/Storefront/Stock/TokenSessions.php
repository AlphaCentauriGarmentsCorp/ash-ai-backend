<?php

namespace App\Support\Storefront\Stock;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The Stock module's login-token store.
 *
 * Port of the Stock manager's App\Support\TokenSessions, which itself replaced the
 * Express port's in-memory `const sessions = {}`. PHP processes share no memory
 * between requests, so the cache plays that role: token -> ['username', 'role'].
 *
 * This is NOT Sanctum and must never become Sanctum. Reefer_Backend's shoppers
 * authenticate against `users` with the api_token column and the framework's auth
 * guards; warehouse staff authenticate against `stock_users` with these tokens. The
 * two populations are separate on purpose, so a token minted here can never satisfy
 * a shopper route and a shopper token can never satisfy a Stock route — there is no
 * shared secret, no shared table and no shared guard between them. Nothing in this
 * class reads config/auth.php or touches `users`.
 *
 * Storage notes:
 *
 * - Keys are namespaced 'stock-session:' so they cannot collide with anything the
 *   shop side caches. On this deployment CACHE_STORE=database, so tokens live in the
 *   `cache` table and survive a restart or a second PHP worker — which is what makes
 *   them work at all under php-fpm.
 *
 * - Cache::forever, matching the source: a staff token dies when they log out, not on
 *   a clock. What keeps that from being permanent access is the middleware, which
 *   re-reads the account on every request — deactivate someone and their next call is
 *   a 401 even though the token itself is still in the cache.
 *
 * - `php artisan cache:clear` therefore logs every staff member out. That is the
 *   documented cost of not standing up a sessions table for fifteen warehouse users.
 */
class TokenSessions
{
    private const PREFIX = 'stock-session:';

    public static function create(string $username, string $role): string
    {
        $token = bin2hex(random_bytes(24));
        Cache::forever(self::PREFIX.$token, ['username' => $username, 'role' => $role]);

        return $token;
    }

    public static function destroy(?string $token): void
    {
        if ($token !== null && $token !== '') {
            Cache::forget(self::PREFIX.$token);
        }
    }

    public static function tokenFromRequest(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }

    /** @return array{username: string, role: string}|null */
    public static function fromRequest(Request $request): ?array
    {
        $token = self::tokenFromRequest($request);
        if ($token === null) {
            return null;
        }

        $session = Cache::get(self::PREFIX.$token);

        return is_array($session) ? $session : null;
    }
}
