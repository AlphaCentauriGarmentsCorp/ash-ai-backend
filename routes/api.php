<?php

use App\Http\Controllers\Api\AccountController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\OrdersController;
use App\Http\Controllers\Api\PatternTypeController;
use App\Http\Controllers\Api\ApparelTypeController;
use App\Http\Controllers\Api\ServiceTypeController;
use App\Http\Controllers\Api\PrintMethodController;
use App\Http\Controllers\Api\SizeLabelController;
use App\Http\Controllers\Api\PrintLabelPlacementController;
use App\Http\Controllers\Api\FreebieController;
use App\Http\Controllers\Api\PlacementMeasurementController;
use App\Http\Controllers\Api\AdditionalOptionController;
use App\Http\Controllers\Api\AddonCategoriesController;
use App\Http\Controllers\Api\AddonsController;
use App\Http\Controllers\Api\EquipmentLocationController;
use App\Http\Controllers\Api\EquipmentInventoryController;
use App\Http\Controllers\Api\DownloadController;
use App\Http\Controllers\Api\GraphicDesignController;
use App\Http\Controllers\Api\MaterialsController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\ScreenController;
use App\Http\Controllers\Api\OrderStagesController;
use App\Http\Controllers\Api\ScreenCheckingController;
use App\Http\Controllers\Api\ScreenMakingController;
use App\Http\Controllers\Api\ScreenMaintenanceController;
use App\Http\Controllers\Api\SewingSubcontractorController;
use App\Http\Controllers\Api\PaymentMethodsController;
use App\Http\Controllers\Api\CourierListController;
use App\Http\Controllers\Api\PrintColorsController;
use App\Http\Controllers\Api\PrintPatternController;
use App\Http\Controllers\Api\TshirtTypesController;
use App\Http\Controllers\Api\TshirtNecklineController;
use App\Http\Controllers\Api\PrintTypesController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\QuotationShareController;
use App\Http\Controllers\Api\PublicQuotationController;
use App\Http\Controllers\Api\SizePricesController;
use App\Http\Controllers\Api\TshirtSizeController;
use App\Http\Controllers\Api\ShippingMethodController;

// example usage: localhost:8000/api/v1/user
// Route::prefix('v1')->group(function () {
//     Route::apiResource('users', UserController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('clients', ClientController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('fabric-types', FabricTypeController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('type-sizes', TypeSizeController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('warehouse-materials', WarehouseMaterialsController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('type-garments', TypeGarmentController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('type-printing-methods', TypePrintingMethodController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('orders', OrdersController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('order-processes', OrderProcessesController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('orders-payment', OrdersPaymentController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('po-statuses', PoStatusController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('po-items', PoItemsController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
//     Route::apiResource('designs', DesignController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
// });

// example usage: localhost:8000/api/v2/user
Route::prefix('v2')->group(function () {
    Route::post('login/reefer', [AuthController::class, 'loginReefer']);
    Route::post('register/reefer', [AuthController::class, 'registerReefer']);

    Route::post('login/sorbetes', [AuthController::class, 'loginSorbetes']);
    Route::post('register/sorbetes', [AuthController::class, 'registerSorbetes']);


    Route::post('login/ash', [AuthController::class, 'loginAsh']);
    Route::post('verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('login', [AuthController::class, 'login']);


    Route::middleware('auth:sanctum')->group(function () {

        Route::post('logout', [AuthController::class, 'logout']);

        Route::get('/me', function (Request $request) {
            return response()->json(Auth::user());
        });
    });

    Route::middleware(['auth:sanctum', 'frontend.access:ash'])->group(function () {
        Route::prefix('/download')->controller(DownloadController::class)->group(function () {
            Route::get('/', 'download');
        });

        Route::prefix('/employee')->controller(AccountController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
        });

        Route::prefix('/clients')->controller(ClientController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/orders')->controller(OrdersController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/details/{po_code}', 'show');
        });

        Route::prefix('/pattern-type')->controller(PatternTypeController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/apparel-type')->controller(ApparelTypeController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/service-type')->controller(ServiceTypeController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/print-method')->controller(PrintMethodController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/size-label')->controller(SizeLabelController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/print-label-placement')->controller(PrintLabelPlacementController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/freebie')->controller(FreebieController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/placement-measurement')->controller(PlacementMeasurementController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/additional-option')->controller(AdditionalOptionController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/equipment-location')->controller(EquipmentLocationController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/equipment-inventory')->controller(EquipmentInventoryController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/{id}/contents', 'getByLocation');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/supplier')->controller(SupplierController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/materials')->controller(MaterialsController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/{id}/supplier', 'getBySupplier');
            Route::get('/type/{type}', 'getByType');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/screens')->controller(ScreenController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/payment-methods')->controller(PaymentMethodsController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/shipping-methods')->controller(ShippingMethodController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/courier-list')->controller(CourierListController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/sewing-subcontractor')->controller(SewingSubcontractorController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/order-stages')->controller(OrderStagesController::class)->group(function () {
            Route::post('/', 'store');
        });

        Route::prefix('/graphic-design')->controller(GraphicDesignController::class)->group(function () {
            Route::post('/', 'store');
        });

        Route::prefix('/screen-making')->controller(ScreenMakingController::class)->group(function () {
            Route::post('/', 'store');
        });

        Route::prefix('/screen-checking')->controller(ScreenCheckingController::class)->group(function () {
            Route::post('/', 'store');
        });


        Route::prefix('/screen-maintenance')->controller(ScreenMaintenanceController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/{id}', 'show');
            Route::get('/user/{id}', 'getByUser');
            Route::post('/', 'store');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/quotation/settings/tshirt-type')->controller(TshirtTypesController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/quotation/settings/tshirt-neckline')->controller(TshirtNecklineController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/quotation/settings/print-types')->controller(PrintTypesController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/quotation/settings/print-pattern')->controller(PrintPatternController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/quotation/settings/tshirt-sizes')->controller(TshirtSizeController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/quotation/settings/addon-categories')->controller(AddonCategoriesController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/quotation/settings/size-prices')->controller(SizePricesController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });


        Route::prefix('/quotation/settings/print-colors')->controller(PrintColorsController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/quotation/settings/addons')->controller(AddonsController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{id}', 'show');
            Route::put('/{id}', 'update');
            Route::delete('/{id}', 'destroy');
        });

        Route::prefix('/quotations')->group(function () {

            // ── Quotation CRUD ────────────────────────────────────────────────
            Route::controller(QuotationController::class)->group(function () {
                Route::get('/', 'index');
                Route::post('/', 'store');
                Route::get('/{id}', 'show');
                Route::get('/{id}/pdf', 'generatePDF');
                Route::put('/{id}', 'update');
                Route::delete('/{id}', 'destroy');
            });

            // ── Share Token Management (authenticated) ────────────────────────
            // permission: 'view'  → read-only filtered public data
            // permission: 'edit'  → read + update items & print parts
            // allow_download: bool → independent PDF download toggle
            //
            // POST   /{id}/share          → generate token
            // GET    /{id}/share          → list tokens
            // DELETE /{id}/share          → revoke all tokens
            // DELETE /share/{token}       → revoke one token
            Route::controller(QuotationShareController::class)->group(function () {
                Route::post('/{id}/share', 'generate');
                Route::get('/{id}/share', 'index');
                Route::delete('/{id}/share', 'revokeAll');
                Route::delete('/share/{token}', 'revoke');
            });
        });

    });

    // ── Public Share Access (NO authentication required) ──────────────────────
    // These routes are intentionally OUTSIDE the auth middleware.
    // Access is governed solely by the validity of the share token.
    //
    // GET /v2/share/quotations/{token}        → filtered view (no prices/names)
    // PUT /v2/share/quotations/{token}        → update items & print parts (edit permission)
    // GET /v2/share/quotations/{token}/pdf    → download PDF (allow_download toggle)
    Route::prefix('/share/quotations')->controller(PublicQuotationController::class)->group(function () {
        Route::get('/{token}', 'show');
        Route::put('/{token}', 'update');
        Route::get('/{token}/pdf', 'pdf');
    });



    Route::middleware(['auth:sanctum', 'frontend.access:sorbetes', 'role:customer'])->group(function () {
        // Add more Ash-specific routes here
    });
    Route::middleware(['auth:sanctum', 'frontend.access:reefer', 'role:customer'])->group(function () {
        // Add more Ash-specific routes here
    });
});