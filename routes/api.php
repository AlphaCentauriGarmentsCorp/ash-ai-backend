<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ClientController;

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

Route::apiResource('user', UserController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);
Route::apiResource('client', ClientController::class) -> only(['index', 'store', 'show', 'update', 'destroy']);

// Route::domain('admin.alphacentauri.com')->group(function () {

//     Route::post('login', [AuthController::class, 'login']);

//     Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
//         // Admin sees EVERYTHING
//         Route::get('/users/all', [AdminUserController::class, 'index']);
//         Route::get('/reports/sales', [ReportController::class, 'sales']);
//         Route::get('/reports/rfqs', [ReportController::class, 'rfqs']);
//     });
// });