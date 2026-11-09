<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ReservationController;
use Illuminate\Support\Facades\Route;

// Public Test / Health Check
Route::get('/ping', function () {
    return response()->json([
        'status'    => 'ok',
        'message'   => 'Yospa API is online and running!',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Public Auth Route
Route::post('/login', [AuthController::class, 'login']);

// Protected Routes (Requires Bearer Token)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('clients', ClientController::class);
    Route::apiResource('reservations', ReservationController::class);
});
