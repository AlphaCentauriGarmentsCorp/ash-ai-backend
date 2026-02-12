<?php

use App\Http\Controllers\Api\AccountController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\FabricTypeController;
use App\Http\Controllers\Api\TypeSizeController;
use App\Http\Controllers\Api\WarehouseMaterialsController;
use App\Http\Controllers\Api\ClientBrandController;
use App\Http\MiddleWare\FrontendAccess;
use App\Http\Controllers\Api\TypeGarmentController;
use App\Http\Controllers\Api\TypePrintingMethodController;
use App\Http\Controllers\Api\OrdersController;
use App\Http\Controllers\Api\OrderProcessesController;
use App\Http\Controllers\Api\OrdersPaymentController;
use App\Http\Controllers\Api\PoStatusController;
use App\Http\Controllers\Api\PoItemsController;
use App\Http\Controllers\Api\DesignController;



Route::middleware('auth:sanctum')->group(function () {

    Route::post('logout', [AuthController::class, 'logout']);

    Route::get('/me', function (Request $request) {
        return response()->json(Auth::user());
    });
});






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

    Route::middleware(['auth:sanctum', 'frontend.access:ash'])->group(function () {


        Route::prefix('/employee')->name('employee.')->controller(AccountController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
        });

        Route::prefix('/clients')->name('clients.')->controller(ClientController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
        });







        // IN PROGRESS
        // Route::apiResource('users', UserController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        // Route::apiResource('clients', ClientController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

        // PENDING


        Route::apiResource('client', ClientController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('fabric-types', FabricTypeController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('type-sizes', TypeSizeController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('warehouse-materials', WarehouseMaterialsController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('type-garments', TypeGarmentController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('type-printing-methods', TypePrintingMethodController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('orders', OrdersController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('order-processes', OrderProcessesController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('order-payments', OrdersPaymentController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('po-statuses', PoStatusController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('po-items', PoItemsController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('designs', DesignController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    });

    Route::middleware(['auth:sanctum', 'frontend.access:sorbetes', 'role:customer'])->group(function () {
        // Add more Ash-specific routes here
    });
    Route::middleware(['auth:sanctum', 'frontend.access:reefer', 'role:customer'])->group(function () {
        // Add more Ash-specific routes here
    });
});













// Route::domain('admin.alphacentauri.com')->group(function () {

//     Route::post('login', [AuthController::class, 'login']);

//     Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
//         // Admin sees EVERYTHING
//         Route::get('/users/all', [AdminUserController::class, 'index']);
//         Route::get('/reports/sales', [ReportController::class, 'sales']);
//         Route::get('/reports/rfqs', [ReportController::class, 'rfqs']);
//     });
// });