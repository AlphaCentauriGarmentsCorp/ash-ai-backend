<?php

namespace App\Http\Middleware\Storefront;

use Illuminate\Http\Request;

/**
 * Pulls the bearer token out of a request. One place, so "what counts as a token"
 * cannot drift between the middleware that demands one and the one that tolerates one.
 */
class ApiToken
{
    public static function fromRequest(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }
}
