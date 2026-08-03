<?php

return [

    // Currency is PHP; prices are stored as whole pesos (integers), no decimals.
    'currency' => 'PHP',

    /*
     * Browser origins the STOREFRONT front-end is served from.
     *
     * config/cors.php belongs to the ERP and is not edited by this integration;
     * StorefrontServiceProvider merges this list INTO cors.allowed_origins at
     * runtime, additively, so the ERP's own origins keep working.
     *
     * Comma-separated in .env, e.g.
     *   STOREFRONT_ALLOWED_ORIGINS="http://localhost:5173,https://shop.example.com"
     * Origin only — scheme + host + port, no path, no trailing slash. Never "*":
     * cors.supports_credentials is on, and a wildcard with credentials is refused
     * by every browser anyway.
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STOREFRONT_ALLOWED_ORIGINS', '')),
    ))),

    /*
     * PayMongo. 'secret' is the switch: set it and StorefrontServiceProvider binds
     * the real gateway, leave it blank and checkout keeps running on the simulator.
     * Nothing else in the app reads these, so an empty block is a working state.
     *
     * These live here rather than in config/services.php because that file is the
     * ERP's and is off-limits to this integration.
     *
     * The secret key must stay server-side. Only 'public' is safe to hand to the
     * SPA (it is what PayMongo.js tokenises cards with).
     */
    'paymongo' => [
        'secret' => env('PAYMONGO_SECRET_KEY'),
        'public' => env('PAYMONGO_PUBLIC_KEY'),

        // Where PayMongo sends the shopper back after an e-wallet checkout. The
        // Sources API requires both, so they fall back to APP_URL rather than
        // being left null and 400-ing the request. When the storefront runs on its
        // own domain these MUST be set explicitly — APP_URL is then the API host,
        // which has no /checkout page to land on.
        //
        // `?:` and not env()'s second argument: env() only falls back when the key is
        // ABSENT, and .env.storefront.example is installed by appending it whole
        // (STOREFRONT_INTEGRATION.md), which leaves these two keys PRESENT-and-empty.
        // With the default-argument form that empty string won, and PayMongo got
        // redirect: {success: '', failed: ''} — a rejected source with nothing in the
        // error pointing at the redirect URL. Treat empty as unset.
        'success_url' => env('PAYMONGO_SUCCESS_URL') ?: rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/checkout/success',
        'failed_url' => env('PAYMONGO_FAILED_URL') ?: rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/checkout/failed',
    ],

    // Human-facing order number: RFR-PH + 7-digit zero-padded sequence.
    'order_prefix' => 'RFR-PH',
    'order_seq_start' => 19005, // demo history in the design ends at RFR-PH0019004

    // Free shipping kicks in at this subtotal (₱). Matches FREE_SHIP in the frontend.
    'free_ship_threshold' => 2500,

    // Shipping methods surfaced on checkout. Fee is in whole pesos.
    // 'golocal' is always free; 'express' is free once subtotal >= free_ship_threshold.
    'shipping_methods' => [
        'golocal' => ['label' => 'GoLocal Regular', 'fee' => 0,   'free_over_threshold' => false],
        'express' => ['label' => 'Reef Express (1–2 days)', 'fee' => 120, 'free_over_threshold' => true],
    ],

    // Payment methods. Online methods are auto-approved by the SimulatedPaymentGateway
    // until PayMongo is wired up. COD stays pending until fulfilment.
    'payment_methods' => ['gcash', 'maya', 'card', 'cod'],

    // Stock at/below this on the whole product flips the "ONLY A FEW LEFT" label.
    'low_stock_threshold' => 6,

    // Fulfilment stages (index 0..4) used by the account/tracking UI.
    'stages' => ['Ordered', 'Packed', 'Shipped', 'Out for Delivery', 'Delivered'],

    // Email verification — the check that actually proves the address exists.
    // Off by default so signup stays testable while MAIL_MAILER=log.
    'require_email_verification' => env('REEFER_REQUIRE_EMAIL_VERIFICATION', false),
    'email_verification_ttl' => 60, // minutes a code stays good

    // Discount codes. Percent codes are capped so a runaway "90% off" cannot be
    // stacked into a free order by a large basket.
    'discounts' => [
        'max_percent' => 60,
    ],

    /*
     * Order fulfilment.
     *
     * allow_manual_advance turns the account page's tracker into a clickable
     * simulator: the buyer can walk their own order Ordered -> Delivered to exercise
     * the flow without a warehouse behind it. Movement is one-way in the controller,
     * so a stage can never be walked back.
     *
     * ⚠ This is a DEMO affordance. In production an order's stage belongs to the
     * warehouse/ERP, not the buyer — someone who can mark their own parcel Delivered
     * can also open the returns window on it, or dispute a delivery they set
     * themselves. Turn it off (REEFER_MANUAL_ADVANCE=false) the moment real
     * fulfilment exists, and move stage changes onto the inventory API.
     */
    'orders' => [
        'allow_manual_advance' => env('REEFER_MANUAL_ADVANCE', true),
        // Stamped on when a simulated order reaches Shipped, so the tracker has
        // something to show where a real courier integration would put its data.
        'demo_courier' => env('REEFER_DEMO_COURIER', 'GoLocal Express'),
    ],

    // Returns.
    'returns' => [
        // Days the customer has to open a return.
        'window_days' => 3,

        /*
         * What the window counts from:
         *   'purchase' — placed_at. "Returnable within 3 days of buying it."
         *   'delivery' — delivered_at, falling back to placed_at when a parcel has
         *                no arrival stamped. Fairer on slow shipping, since transit
         *                time no longer eats the window.
         */
        'window_from' => env('REEFER_RETURN_WINDOW_FROM', 'purchase'),

        // Whether the parcel must have arrived first. A return presupposes receipt —
        // before that it is a cancellation, which is a different thing.
        'require_delivered' => env('REEFER_RETURN_REQUIRE_DELIVERED', true),
        'reasons' => [
            'wrong_size' => 'Wrong size',
            'damaged' => 'Arrived damaged',
            'not_as_described' => 'Not as described',
            'wrong_item' => 'Wrong item sent',
            'changed_mind' => 'Changed my mind',
        ],
        // Statuses a return moves through. 'requested' is the only one a customer can
        // set; the rest are the shop's side of the conversation.
        'statuses' => ['requested', 'approved', 'rejected', 'received', 'refunded', 'cancelled'],
    ],

    /*
     * Machine-to-machine inventory API, for the ERP / stock system that owns
     * quantities and availability.
     *
     * on_hand and the Active flag are ITS to write; the storefront only ever raises
     * `allocated` when an order reserves a unit. Leave the token blank and the whole
     * /api/v1/inventory surface answers 503 — no half-open integration.
     *
     * Generate one with: php -r "echo bin2hex(random_bytes(32));"
     */
    'inventory' => [
        'token' => env('INVENTORY_API_TOKEN'),
        // Warehouse/marketplace labels stamped on rows the ERP has not named yet, so
        // an export is never missing the columns it expects.
        'default_warehouse' => env('INVENTORY_WAREHOUSE', 'Reefer QC'),
        'default_marketplace' => env('INVENTORY_MARKETPLACE', 'REEFER (Website)'),
    ],

    // Back-in-stock alerts. One pending alert per account per variant; a restock
    // notifies and then clears it, so nobody gets told twice about one restock.
    'stock_alerts' => [
        // Guards the notify job against blasting a huge backlog in one run.
        'max_per_run' => 200,
    ],
];
