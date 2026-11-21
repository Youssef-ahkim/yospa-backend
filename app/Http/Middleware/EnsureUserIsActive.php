<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $freshUser = $user->fresh(['spa']);

            if (
                ! $freshUser
                || ! $freshUser->is_active
                || ($freshUser->spa_id && $freshUser->spa?->status === 'suspended')
                || (! $freshUser->spa_id && $freshUser->role !== 'superuser')
            ) {
                $user->currentAccessToken()?->delete();

                return response()->json([
                    'message' => 'Your account has been deactivated.',
                ], 403);
            }
        }

        return $next($request);
    }
}
