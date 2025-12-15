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

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    // Protected routes go here
    Route::get('profile', function(Request $request){
        return $request->user();
    });
});


// example usage: localhost:8000/api/v1/user
Route::prefix('v1')->group(function () {
    Route::apiResource('user', UserController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('client', ClientController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('fabric-type', FabricTypeController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('type-size', TypeSizeController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('client-brands', ClientBrandController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('warehouse-materials', WarehouseMaterialsController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
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