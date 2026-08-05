<?php

namespace App\Services\Storefront;

use App\Contracts\Storefront\PaymentGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PayMongo — the Philippine processor, chosen because it covers exactly the
 * online methods in config('reefer.payment_methods'): card, gcash, maya.
 *
 * Two different primitives are involved. Cards go through a Payment Intent that
 * the SPA finishes client-side with the returned client_key (3DS happens there).
 * E-wallets go through a Source, which returns a checkout_url the shopper is
 * redirected to. Neither settles inside this request, so charge() returns
 * 'pending' for both — see the note on webhooks below.
 *
 * UNVERIFIED. Written without API keys and never run against PayMongo's sandbox.
 * Do not trust it until someone has put a real test key in and placed an order
 * end to end.
 */
class PayMongoGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.paymongo.com/v1';

    // PayMongo rejects anything under ₱100. Catching it here turns an opaque 400
    // into a log line that names the actual problem.
    private const MINIMUM_CENTAVOS = 10000;

    // config('reefer.payment_methods') names → PayMongo source types. 'maya' is
    // still 'paymaya' on the wire; the rename never reached the API.
    private const SOURCE_TYPES = [
        'gcash' => 'gcash',
        'maya' => 'paymaya',
    ];

    public function charge(string $method, int $amount, ?string $simulate = null): PaymentResult
    {
        // Cash on delivery collects no money now, and there is nothing for a
        // processor to do with it. It must never reach PayMongo.
        if ($method === 'cod') {
            return new PaymentResult('pending');
        }

        // Same escape hatch the simulator honours, so the demo's "SIMULATE
        // FAILURE" button still works against real keys in dev without spending
        // an API call. Production ignores it, as it does there.
        if ($simulate === 'fail' && ! app()->isProduction()) {
            return new PaymentResult('declined');
        }

        // PayMongo works in centavos; $amount is whole pesos (config/reefer.php
        // stores prices as integers). This ×100 is the difference between
        // charging ₱1,200 and charging ₱120,000, so it is spelled out once here
        // and never repeated below.
        $centavos = $amount * 100;

        if ($centavos < self::MINIMUM_CENTAVOS) {
            Log::error('PayMongo charge below the processor minimum', [
                'method' => $method,
                'amount_php' => $amount,
                'minimum_php' => (int) (self::MINIMUM_CENTAVOS / 100),
            ]);

            return new PaymentResult('declined');
        }

        try {
            if ($method === 'card') {
                return $this->createPaymentIntent($centavos);
            }

            $sourceType = self::SOURCE_TYPES[$method] ?? null;

            if ($sourceType === null) {
                Log::error('PayMongo has no mapping for this payment method', ['method' => $method]);

                return new PaymentResult('declined');
            }

            return $this->createSource($sourceType, $centavos);
        } catch (Throwable $e) {
            // This runs inside OrderController's DB transaction. An exception
            // escaping here rolls the order back as a 500; a decline rolls it
            // back with something the shopper can act on. Always the decline.
            Log::error('PayMongo request failed', [
                'method' => $method,
                'amount_php' => $amount,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return new PaymentResult('declined');
        }
    }

    private function createPaymentIntent(int $centavos): PaymentResult
    {
        $response = $this->client()->post(self::BASE_URL.'/payment_intents', [
            'data' => [
                'attributes' => [
                    'amount' => $centavos,
                    'currency' => config('reefer.currency'),
                    'payment_method_allowed' => ['card'],
                    'capture_type' => 'automatic',
                    'description' => config('app.name').' order',
                ],
            ],
        ]);

        if ($response->failed()) {
            return $this->decline('payment_intents', $response);
        }

        $data = $response->json('data');

        // 'awaiting_payment_method' — the card itself is attached by the SPA with
        // the client_key, and the intent only reaches 'succeeded' after that.
        return new PayMongoPaymentResult(
            'pending',
            $data['id'] ?? null,
            clientKey: $data['attributes']['client_key'] ?? null,
        );
    }

    private function createSource(string $type, int $centavos): PaymentResult
    {
        $response = $this->client()->post(self::BASE_URL.'/sources', [
            'data' => [
                'attributes' => [
                    'amount' => $centavos,
                    'currency' => config('reefer.currency'),
                    'type' => $type,
                    // config('reefer.paymongo.*'), not config('services.paymongo.*'):
                    // the storefront port may not edit the ERP's config/services.php,
                    // so every PayMongo key lives in the storefront's own
                    // config/reefer.php. The Sources API requires both URLs, so they
                    // must be given values there rather than left null.
                    'redirect' => [
                        'success' => config('reefer.paymongo.success_url'),
                        'failed' => config('reefer.paymongo.failed_url'),
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            return $this->decline('sources', $response);
        }

        $data = $response->json('data');

        return new PayMongoPaymentResult(
            'pending',
            $data['id'] ?? null,
            redirectUrl: $data['attributes']['redirect']['checkout_url'] ?? null,
        );
    }

    private function client(): PendingRequest
    {
        // Basic auth with the SECRET key as the username and an empty password —
        // PayMongo's whole auth scheme.
        //
        // No retry() on purpose: creating an intent or a source is not
        // idempotent, so a retried POST after a slow-but-successful call bills
        // the shopper twice. A timeout here is a decline, not a second attempt.
        return Http::withBasicAuth((string) config('reefer.paymongo.secret'), '')
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);
    }

    private function decline(string $endpoint, Response $response): PaymentResult
    {
        Log::error('PayMongo rejected the request', [
            'endpoint' => $endpoint,
            'status' => $response->status(),
            // PayMongo's error body is codes and messages only — no card data,
            // so it is safe to put in a log.
            'errors' => $response->json('errors'),
        ]);

        return new PaymentResult('declined');
    }
}
