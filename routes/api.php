<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\ServiceController;
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

// Protected Routes (Requires Bearer Token & Active User)
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Clients: Read & Write open to all roles; Delete restricted to owner and admin
    Route::apiResource('clients', ClientController::class)->except(['destroy']);
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])
        ->name('clients.destroy')
        ->middleware('role:owner,admin');

    // Reservations: Read & Write open to all roles; Delete restricted to owner and admin
    Route::apiResource('reservations', ReservationController::class)->except(['destroy']);
    Route::delete('reservations/{reservation}', [ReservationController::class, 'destroy'])
        ->name('reservations.destroy')
        ->middleware('role:owner,admin');

    // Services: Read open to all roles; Write/Delete restricted to owner and admin
    Route::apiResource('services', ServiceController::class)->only(['index', 'show']);
    Route::apiResource('services', ServiceController::class)->only(['store', 'update', 'destroy'])
        ->middleware('role:owner,admin');
});
