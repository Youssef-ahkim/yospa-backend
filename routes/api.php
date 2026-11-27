<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\Platform\PlatformAuditController;
use App\Http\Controllers\Api\Platform\PlatformSpaController;
use App\Http\Controllers\Api\Platform\PlatformStatsController;
use App\Http\Controllers\Api\Platform\PlatformTicketController;
use App\Http\Controllers\Api\Platform\PlatformUserController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\TherapistController;
use App\Http\Controllers\Api\TicketController;
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
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Protected Routes (Requires Bearer Token & Active User)
Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/user/password', [AuthController::class, 'changePassword'])
        ->middleware('throttle:password_change');

    // Tenant-scoped routes (Requires user to belong to an active spa)
    Route::middleware('tenant')->group(function () {
        // Dashboard aggregate summary and reporting
        Route::get('dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('dashboard/sales-report', [DashboardController::class, 'salesReport']);
        Route::get('dashboard/best-sellers', [DashboardController::class, 'bestSellers']);

        // Staff Management (Owner and Admin only)
        Route::middleware('role:owner,admin')->group(function () {
            Route::get('staff', [StaffController::class, 'index']);
            Route::post('staff', [StaffController::class, 'store']);
            Route::patch('staff/{id}', [StaffController::class, 'update']);

            // Support Tickets
            Route::get('tickets', [TicketController::class, 'index']);
            Route::post('tickets', [TicketController::class, 'store']);
            Route::get('tickets/{ticket}', [TicketController::class, 'show']);
            Route::post('tickets/{ticket}/messages', [TicketController::class, 'storeMessage']);
            Route::patch('tickets/{ticket}/close', [TicketController::class, 'close']);
        });

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

        // Therapists: Read open to all roles; Write/Delete restricted to owner and admin
        Route::apiResource('therapists', TherapistController::class)->only(['index', 'show']);
        Route::apiResource('therapists', TherapistController::class)->only(['store', 'update', 'destroy'])
            ->middleware('role:owner,admin');

        // Products: Read open to all roles; Write/Delete restricted to owner and admin
        Route::apiResource('products', ProductController::class)->only(['index', 'show']);
        Route::apiResource('products', ProductController::class)->only(['store', 'update', 'destroy'])
            ->middleware('role:owner,admin');
    });

    // Platform Superuser Routes (Never scoped to a single spa, no tenant middleware)
    Route::prefix('platform')->middleware(['role:superuser', 'throttle:60,1'])->group(function () {
        Route::get('stats', [PlatformStatsController::class, 'index']);
        Route::get('spas', [PlatformSpaController::class, 'index']);
        Route::post('spas', [PlatformSpaController::class, 'store']);
        Route::get('spas/{id}', [PlatformSpaController::class, 'show']);
        Route::patch('spas/{id}', [PlatformSpaController::class, 'update']);
        Route::delete('spas/{id}', [PlatformSpaController::class, 'destroy']);
        Route::post('spas/{id}/users', [PlatformSpaController::class, 'storeUser']);
        Route::patch('users/{id}', [PlatformUserController::class, 'update']);
        Route::get('audit', [PlatformAuditController::class, 'index']);

        // Platform Support Tickets
        Route::get('tickets', [PlatformTicketController::class, 'index']);
        Route::get('tickets/{id}', [PlatformTicketController::class, 'show']);
        Route::patch('tickets/{id}', [PlatformTicketController::class, 'update']);
        Route::post('tickets/{id}/messages', [PlatformTicketController::class, 'storeMessage']);
    });
});
