<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));
            $ip = $request->ip();

            return [
                Limit::perMinute(5)->by($email.'|'.$ip)->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Too many login attempts. Please try again later.',
                    ], 429, $headers);
                }),
                Limit::perMinute(20)->by($ip)->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Too many login attempts. Please try again later.',
                    ], 429, $headers);
                }),
            ];
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip())->response(function (Request $request, array $headers) {
                return response()->json([
                    'message' => 'Too many requests. Please slow down.',
                ], 429, $headers);
            });
        });

        RateLimiter::for('password_change', function (Request $request) {
            $userId = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(5)->by($userId)->response(function (Request $request, array $headers) {
                return response()->json([
                    'message' => 'Too many password change attempts. Please try again later.',
                ], 429, $headers);
            });
        });
    }
}
