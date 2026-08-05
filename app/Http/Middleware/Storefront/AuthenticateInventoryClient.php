<?php

namespace App\Http\Middleware\Storefront;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the machine-to-machine inventory API.
 *
 * Deliberately NOT the shopper bearer token: these endpoints belong to another
 * system, not to a person, and a leaked shopper token must never be able to rewrite
 * warehouse quantities or pull the whole stock ledger.
 *
 * With no INVENTORY_API_TOKEN configured the whole surface answers 503 rather than
 * running open — an integration that silently accepts anonymous writes is worse than
 * one that is switched off.
 */
class AuthenticateInventoryClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('reefer.inventory.token');

        if ($expected === '') {
            return response()->json([
                'message' => 'The inventory API is not configured on this server.',
            ], 503);
        }

        $presented = (string) ($request->bearerToken() ?? $request->header('X-Inventory-Token', ''));

        // hash_equals, not ===: a plain comparison returns as soon as it finds a
        // differing byte, which leaks the token a character at a time to anyone
        // willing to time the responses.
        if ($presented === '' || ! hash_equals($expected, $presented)) {
            return response()->json(['message' => 'Invalid inventory credentials.'], 401);
        }

        return $next($request);
    }
}
