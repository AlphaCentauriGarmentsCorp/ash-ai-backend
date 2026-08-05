<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Runtime front-end config. Public and unauthenticated, so everything in here has
 * to be public by design — this response is readable by anyone who can reach the
 * site, and treating it as anything else is how a key ends up in a bundle.
 *
 * It exists because the alternative is baking these into the SPA at build time,
 * which makes "add Google credentials" a rebuild rather than an .env line.
 */
class ConfigController extends Controller
{
    public function index(): JsonResponse
    {
        $googleClientId = (string) config('services.google.client_id');

        return response()->json([
            'data' => [
                // null, never '' — the SPA branches on this to decide whether the
                // Google button exists at all, and an empty string reads as "set"
                // to anything doing a presence check.
                'google_client_id' => $googleClientId !== '' ? $googleClientId : null,
                'free_ship_threshold' => (int) config('reefer.free_ship_threshold'),
                'currency' => (string) config('reefer.currency'),
                'payment_methods' => array_values((array) config('reefer.payment_methods')),
            ],
        ]);
    }
}
