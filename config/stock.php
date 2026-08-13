<?php

/*
 * Stock manager (warehouse ERP) module configuration.
 *
 * Port of the Stock manager app's config/erp.php. Nothing here belongs to the
 * shop side of this backend — shopper-facing settings live in config/reefer.php
 * and the two must not be mixed. Read as config('stock.*').
 */

return [

    /*
     * Machine-to-machine token for the storefront (this backend's own shop side,
     * or ash-ai-backend) calling the Stock module.
     *
     * The /api/stocks/inventory and /api/stocks/orders surfaces sit behind the
     * module's auth middleware. Staff browsers authenticate with the Bearer token
     * they got at POST /api/stocks/auth/login; a service caller authenticates with
     * this fixed token instead (set the same value in the consuming app's .env —
     * ash-ai-backend reads it as REEFER_ERP_TOKEN).
     *
     * Blank = no service access: only logged-in staff can reach the API. The
     * comparison is constant-time and an empty configured token never matches.
     *
     * NOTE this is a DIFFERENT token from reefer.inventory.token
     * (INVENTORY_API_TOKEN), which guards the shop's own /api/v1/inventory
     * surface. Two separate systems, two separate secrets — do not reuse one
     * value for both.
     *
     * Generate one with: php -r "echo bin2hex(random_bytes(32));"
     */
    'service_token' => env('STOCK_SERVICE_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Publishing
    |--------------------------------------------------------------------------
    |
    | The stock manager and the storefront share `products` and `product_variants`,
    | so a design created here IS the shop's product page — there is no sync step.
    | What decides whether customers can see it is products.is_active, which the
    | storefront filters on and which InventoryData::syncProductActive() keeps
    | pointed at the truth of the design's sizes.
    |
    | 'auto' true  — a NEW design goes live as soon as it is shoppable: it has a
    |                price of at least min_price and at least one size with stock.
    |                A half-typed SKU (no price, or nothing on hand) still lands
    |                dark, which was the original reason new rows were forced
    |                inactive: "an empty product page goes live the moment a
    |                warehouse hand types a SKU."
    |
    | 'auto' false — approve-before-live. Everything lands dark regardless of how
    |                complete it is, and somebody publishes it deliberately with the
    |                status pill on the Product Catalog screen.
    |
    | Either way the pill is the override, in both directions, at any time.
    |
    */
    'publish' => [
        'auto' => (bool) env('STOCK_AUTO_PUBLISH', true),

        // Guards against a design going live at ₱0, which the storefront would
        // happily sell. Raise it if a floor price is ever agreed.
        'min_price' => (int) env('STOCK_PUBLISH_MIN_PRICE', 1),
    ],

];
