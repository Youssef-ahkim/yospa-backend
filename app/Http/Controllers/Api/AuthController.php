<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated.',
            ], 403);
        }

        if ($user->spa_id && $user->spa?->status === 'suspended') {
            return response()->json([
                'message' => 'Your account has been deactivated.',
            ], 403);
        }

        if (! $user->spa_id && $user->role !== 'superuser') {
            return response()->json([
                'message' => 'Your account has been deactivated.',
            ], 403);
        }

        // Generate a Sanctum token for the user (superusers expire in 12 hours, tenant tokens unchanged)
        $expiresAt = $user->role === 'superuser' ? now()->addHours(12) : null;
        $token = $user->createToken('spa-token', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'message' => 'Logged in successfully',
            'token'   => $token,
            'user'    => $user->load('spa'),
        ]);
    }

    public function user(Request $request)
    {
        return response()->json($request->user()->load('spa'));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        // Verify current password
        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        // New password must differ from current password
        if (Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The new password must be different from the current password.'],
            ]);
        }

        DB::transaction(function () use ($user, $request) {
            $user->password = $request->password;
            $user->save();

            // Revoke all other Sanctum tokens, keeping the current one
            $currentToken = $user->currentAccessToken();
            if ($currentToken instanceof PersonalAccessToken) {
                $user->tokens()->where('id', '!=', $currentToken->id)->delete();
            } else {
                $user->tokens()->delete();
            }
        });

        return response()->json([
            'message' => 'Password updated successfully',
        ]);
    }
}
