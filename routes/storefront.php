<?php

use App\Http\Controllers\Storefront\AccountController;
use App\Http\Controllers\Storefront\AddressController;
use App\Http\Controllers\Storefront\AuthController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\ConfigController;
use App\Http\Controllers\Storefront\DiscountController;
use App\Http\Controllers\Storefront\EmailVerificationController;
use App\Http\Controllers\Storefront\FavoriteController;
use App\Http\Controllers\Storefront\HealthController;
use App\Http\Controllers\Storefront\InventoryController;
use App\Http\Controllers\Storefront\OrderController;
use App\Http\Controllers\Storefront\PasswordResetController;
use App\Http\Controllers\Storefront\ProductController;
use App\Http\Controllers\Storefront\ProductReviewController;
use App\Http\Controllers\Storefront\ReturnRequestController;
use App\Http\Controllers\Storefront\StockAlertController;
use App\Http\Middleware\Storefront\AuthenticateApiToken;
use App\Http\Middleware\Storefront\AuthenticateInventoryClient;
use App\Http\Middleware\Storefront\ResolveApiUser;
use Illuminate\Support\Facades\Route;

/*
 * Storefront API. Mounted by StorefrontServiceProvider under the `api/storefront`
 * prefix with the `api` middleware group, so every URL below ends up at
 * /api/storefront/v1/... — the ERP keeps /api/v1/* to itself (both sides define
 * /v1/orders, and a flat mount would have collided).
 *
 * Do NOT add the prefix here; the provider owns it.
 */

Route::get('/test', [HealthController::class, 'index']);

    
Route::prefix('v1')->group(function () {
    Route::get('/health', [HealthController::class, 'index']);

    // Runtime front-end config (currency, free-shipping threshold, payment methods).
    // Public and unauthenticated by design — the SPA reads it before anyone signs in,
    // so nothing secret may ever be added to the payload. Never registered in the
    // source project, which is why ConfigEndpointTest failed there and the SPA's
    // configApi.js 404'd; registering it is the fix.
    Route::get('/config', [ConfigController::class, 'index']);

    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:storefront-auth')->group(function () {
            Route::post('/register', [AuthController::class, 'register']);
            Route::post('/login', [AuthController::class, 'login']);

            // Public on purpose — someone locked out cannot authenticate. Throttled
            // because these send mail and can be used to probe which emails exist,
            // so both always answer the same way whether or not the account is real.
            Route::post('/forgot-password', [PasswordResetController::class, 'sendLink']);
            Route::post('/reset-password', [PasswordResetController::class, 'reset']);
        });

        Route::middleware(AuthenticateApiToken::class)->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);

            // Email verification. Enforced only when reefer.require_email_verification
            // is on; the endpoints exist either way so the flag is a switch, not a port.
            Route::post('/email/send', [EmailVerificationController::class, 'send'])->middleware('throttle:storefront-auth-user');
            Route::post('/email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:storefront-auth-user');
        });
    });

    /*
     * Machine-to-machine inventory ledger for the external stock system.
     *
     * Its own token (INVENTORY_API_TOKEN), NOT a shopper bearer token — this reads and
     * rewrites warehouse quantities for the whole catalogue, which is not something a
     * customer session should ever be able to reach. Unconfigured, it answers 503.
     */
    Route::middleware(AuthenticateInventoryClient::class)->prefix('inventory')->group(function () {
        Route::get('/', [InventoryController::class, 'index']);
        Route::post('/sync', [InventoryController::class, 'sync']);
        Route::match(['put', 'patch'], '/{sku}', [InventoryController::class, 'update']);
    });

    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);

    // Reviews are readable by everyone. ResolveApiUser (not AuthenticateApiToken)
    // means a signed-out visitor still gets the ratings, while a signed-in one is
    // additionally told whether they are allowed to leave one.
    Route::middleware(ResolveApiUser::class)
        ->get('/products/{product}/reviews', [ProductReviewController::class, 'index']);

    // Writing needs an account; the controller then checks they bought it.
    Route::middleware(AuthenticateApiToken::class)->group(function () {
        Route::post('/products/{product}/reviews', [ProductReviewController::class, 'store']);
        Route::delete('/products/{product}/reviews/mine', [ProductReviewController::class, 'destroy']);
    });

    Route::middleware(AuthenticateApiToken::class)->group(function () {
        Route::get('/addresses', [AddressController::class, 'index']);
        Route::post('/addresses', [AddressController::class, 'store']);
        Route::match(['put', 'patch'], '/addresses/{address}', [AddressController::class, 'update']);
        Route::delete('/addresses/{address}', [AddressController::class, 'destroy']);

        // The cart belongs to an account, so every route here is behind auth.
        Route::get('/cart', [CartController::class, 'show']);
        Route::delete('/cart', [CartController::class, 'clear']);
        Route::post('/cart/merge', [CartController::class, 'merge']);
        Route::post('/cart/select', [CartController::class, 'select']);
        Route::post('/cart/items', [CartController::class, 'store']);
        // {item} is a plain id, not a bound model — see CartController::findOwnedItem.
        Route::match(['put', 'patch'], '/cart/items/{item}', [CartController::class, 'update'])->whereNumber('item');
        Route::delete('/cart/items/{item}', [CartController::class, 'destroy'])->whereNumber('item');

        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);

        // Demo-only: walk a simulated order down the tracker. One-way, owner-scoped,
        // and switched off by REEFER_MANUAL_ADVANCE=false once real fulfilment exists.
        Route::post('/orders/{order}/advance', [OrderController::class, 'advance']);

        // Preview what a code is worth before committing to it. The order endpoint
        // re-resolves and re-prices the code itself — this is for the UI, not the money.
        Route::post('/discounts/validate', [DiscountController::class, 'validateCode']);

        Route::get('/account', [AccountController::class, 'dashboard']);
        Route::match(['put', 'patch'], '/account', [AccountController::class, 'update']);

        // Wishlist. Account-tied, so all behind auth.
        Route::get('/favorites', [FavoriteController::class, 'index']);
        Route::post('/favorites/toggle', [FavoriteController::class, 'toggle']);
        Route::delete('/favorites/{product}', [FavoriteController::class, 'destroy']);

        // "Tell me when my size is back." One pending alert per account per variant;
        // a restock sends the mail and clears it, so a second restock is a fresh ask.
        Route::get('/stock-alerts', [StockAlertController::class, 'index']);
        Route::post('/stock-alerts', [StockAlertController::class, 'store']);
        Route::delete('/stock-alerts/{stockAlert}', [StockAlertController::class, 'destroy']);

        // Returns. A customer may open one against their own delivered order and
        // cancel it while it is still pending; every other transition is the shop's.
        Route::get('/returns', [ReturnRequestController::class, 'index']);
        Route::get('/returns/{returnRequest}', [ReturnRequestController::class, 'show']);
        Route::post('/orders/{order}/returns', [ReturnRequestController::class, 'store']);
        Route::post('/returns/{returnRequest}/cancel', [ReturnRequestController::class, 'cancel']);
    });
});


/*
|--------------------------------------------------------------------------
| Stock manager (staff ERP)
|--------------------------------------------------------------------------
|
| Ported from Reefer_Backend under the same rules as the rest of this file:
| every class carries a `Storefront` segment and every table a `storefront_`
| prefix, so nothing here can reach the ASH ERP's own `orders`/`products`.
|
| The provider owns the `api/storefront` prefix, so these land at
| /api/storefront/stocks/* — which is what reefer-frontend's stock client
| builds (VITE_API_URL + '/stocks'). They sit OUTSIDE the /v1 group on
| purpose: /v1 is the shopper API, this is the staff one, and they
| authenticate against different tables with different tokens.
|
| Login/register use `throttle:storefront-auth`, this app's limiter — the
| source's bare `throttle:auth` is the ERP's and means something else here.
|
*/
Route::prefix('stocks')->group(function () {

    // Public: the login and register screens have no session yet. Throttled with the
    // storefront's own limiter — brute-forcing a warehouse login is worth no less
    // effort to an attacker than brute-forcing a customer one.
    Route::middleware('throttle:storefront-auth')->group(function () {
        Route::post('auth/register', [\App\Http\Controllers\Storefront\Stock\AuthController::class, 'register']);
        Route::post('auth/login', [\App\Http\Controllers\Storefront\Stock\AuthController::class, 'login']);
    });

    Route::middleware(\App\Http\Middleware\Storefront\Stock\StockAuthenticate::class)->group(function () {

        Route::post('auth/logout', [\App\Http\Controllers\Storefront\Stock\AuthController::class, 'logout']);

        /*
         | Inventory.
         |
         | ORDER MATTERS HERE. Every literal segment is declared before the
         | `{sku}` wildcard, or `inventory/export` would be swallowed as a SKU
         | named "export" — a 404 that looks like a missing feature rather than a
         | routing mistake. Same reason pending-edits sits above it.
         */
        Route::get('inventory/logs', [\App\Http\Controllers\Storefront\Stock\InventoryController::class, 'logs']);
        Route::get('inventory/export', [\App\Http\Controllers\Storefront\Stock\InventoryController::class, 'export']);
        Route::get('inventory/import-template', [\App\Http\Controllers\Storefront\Stock\InventoryController::class, 'importTemplate']);
        Route::post('inventory/import-xlsx', [\App\Http\Controllers\Storefront\Stock\InventoryController::class, 'importXlsx']);
        Route::post('inventory/photo', [\App\Http\Controllers\Storefront\Stock\InventoryController::class, 'photo']);
        Route::post('inventory/product', [\App\Http\Controllers\Storefront\Stock\InventoryController::class, 'createProduct']);

        // Push Product review queue. Declared before inventory/{sku} for the same reason.
        Route::get('inventory/pending-edits', [\App\Http\Controllers\Storefront\Stock\PendingEditsController::class, 'index']);
        Route::post('inventory/pending-edits', [\App\Http\Controllers\Storefront\Stock\PendingEditsController::class, 'store']);
        Route::post('inventory/pending-edits/push-all', [\App\Http\Controllers\Storefront\Stock\PendingEditsController::class, 'pushAll']);
        Route::post('inventory/pending-edits/{id}/push', [\App\Http\Controllers\Storefront\Stock\PendingEditsController::class, 'push'])->whereNumber('id');
        Route::delete('inventory/pending-edits/{id}', [\App\Http\Controllers\Storefront\Stock\PendingEditsController::class, 'destroy'])->whereNumber('id');

        Route::get('inventory', [\App\Http\Controllers\Storefront\Stock\InventoryController::class, 'index']);
        Route::put('inventory/{sku}', [\App\Http\Controllers\Storefront\Stock\InventoryController::class, 'update']);
        Route::delete('inventory/{sku}', [\App\Http\Controllers\Storefront\Stock\InventoryController::class, 'destroy']);

        // Catalog: the same designs seen product-first rather than SKU-first, plus the
        // website-content fields that live on `products`.
        Route::get('catalog', [\App\Http\Controllers\Storefront\Stock\CatalogController::class, 'index']);
        Route::get('catalog/rows', [\App\Http\Controllers\Storefront\Stock\CatalogController::class, 'rows']);
        Route::get('catalog/design/{code}', [\App\Http\Controllers\Storefront\Stock\CatalogController::class, 'design']);
        Route::get('catalog/content/{code}', [\App\Http\Controllers\Storefront\Stock\CatalogController::class, 'content']);

        // Orders. `report` before `{order}` — same wildcard rule as above.
        Route::get('orders/report', [\App\Http\Controllers\Storefront\Stock\OrdersController::class, 'report']);
        Route::get('orders', [\App\Http\Controllers\Storefront\Stock\OrdersController::class, 'index']);
        Route::post('orders', [\App\Http\Controllers\Storefront\Stock\OrdersController::class, 'store']);
        Route::put('orders/{order}', [\App\Http\Controllers\Storefront\Stock\OrdersController::class, 'updateStatus']);

        // Staff administration. Approving, rejecting and deactivating accounts is
        // admin-only; StockRequireAdmin re-checks the session rather than trusting
        // the outer middleware's decision.
        Route::middleware(\App\Http\Middleware\Storefront\Stock\StockRequireAdmin::class)->group(function () {
            Route::get('auth/users', [\App\Http\Controllers\Storefront\Stock\AuthController::class, 'listUsers']);
            Route::put('auth/users/{username}/approve', [\App\Http\Controllers\Storefront\Stock\AuthController::class, 'approve']);
            Route::put('auth/users/{username}/reject', [\App\Http\Controllers\Storefront\Stock\AuthController::class, 'reject']);
            Route::put('auth/users/{username}/deactivate', [\App\Http\Controllers\Storefront\Stock\AuthController::class, 'deactivate']);
            Route::put('auth/users/{username}/reactivate', [\App\Http\Controllers\Storefront\Stock\AuthController::class, 'reactivate']);
            Route::put('auth/users/{username}/edit', [\App\Http\Controllers\Storefront\Stock\AuthController::class, 'edit']);
        });
    });
});
