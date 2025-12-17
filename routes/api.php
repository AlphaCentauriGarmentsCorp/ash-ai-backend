<?php

use Illuminate\Http\Request;
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


// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');


Route::post('login', [AuthController::class, 'login']);
Route::post('register', [AuthController::class, 'register']);
Route::post('verify-otp', [AuthController::class, 'verifyOtp']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    // Protected routes go here
    Route::get('profile', function(Request $request){
        return $request->user();
    });
});


// example usage: localhost:8000/api/v1/user
Route::prefix('v1')->group(function () {
    Route::apiResource('users', UserController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('clients', ClientController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('fabric-types', FabricTypeController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('type-sizes', TypeSizeController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('client-brands', ClientBrandController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('warehouse-materials', WarehouseMaterialsController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('type-garments', TypeGarmentController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('type-printing-methods', TypePrintingMethodController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('orders', OrdersController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('order-processes', OrderProcessesController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('order-payments', OrdersPaymentController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('po-statuses', PoStatusController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

});

// example usage: localhost:8000/api/v2/user
Route::prefix('v2')->group(function () {
    Route::middleware(['auth:sanctum', 'frontend.access:ash', 'role:admin'])->group(function () {
        Route::apiResource('user', UserController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('client', ClientController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('fabric-type', FabricTypeController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('type-size', TypeSizeController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('warehouse-materials', WarehouseMaterialsController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        // Add more Ash-specific routes here
    });

    Route::middleware(['auth:sanctum', 'frontend.access:sorbetes', 'role:customer'])->group(function () {
        Route::apiResource('client-brands', ClientBrandController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
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