<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class PlatformUserController extends Controller
{
    public function update(Request $request, $id)
    {
        $user = User::find($id);

        // 404 for the superuser account or any user without a spa
        if (! $user || $user->role === 'superuser' || $user->spa_id === null) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        $validated = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'email'     => 'sometimes|email|max:255',
            'role'      => 'sometimes|string|in:owner,admin,staff',
            'is_active' => 'sometimes|boolean',
            'password'  => 'sometimes|string|min:8',
        ]);

        if (array_key_exists('email', $validated)) {
            $exists = User::whereRaw('LOWER(email) = ?', [strtolower($validated['email'])])
                ->where('id', '!=', $user->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'The email has already been taken.',
                    'errors'  => [
                        'email' => ['The email has already been taken.'],
                    ],
                ], 422);
            }
        }

        // 409 guard: cannot deactivate or demote the last active owner of that spa
        if ($user->role === 'owner') {
            $demoting = isset($validated['role']) && $validated['role'] !== 'owner';
            $deactivating = array_key_exists('is_active', $validated) && ! $validated['is_active'];

            if ($demoting || $deactivating) {
                $otherActiveOwners = User::where('spa_id', $user->spa_id)
                    ->where('role', 'owner')
                    ->where('is_active', true)
                    ->where('id', '!=', $user->id)
                    ->count();

                if ($otherActiveOwners === 0) {
                    return response()->json([
                        'message' => 'Cannot deactivate or change role of the last active owner.',
                    ], 409);
                }
            }
        }

        $data = [];
        $metaChanges = [];
        $revokeTokens = false;

        if (array_key_exists('name', $validated)) {
            $data['name'] = $validated['name'];
            $metaChanges['name'] = $validated['name'];
        }

        if (array_key_exists('email', $validated)) {
            $data['email'] = $validated['email'];
            $metaChanges['email'] = $validated['email'];
        }

        if (array_key_exists('role', $validated)) {
            $data['role'] = $validated['role'];
            $metaChanges['role'] = $validated['role'];
            if ($validated['role'] !== $user->role) {
                $revokeTokens = true;
            }
        }

        if (array_key_exists('is_active', $validated)) {
            $data['is_active'] = $validated['is_active'];
            $metaChanges['is_active'] = $validated['is_active'];
            if (! $validated['is_active']) {
                $revokeTokens = true;
            }
        }

        if (! empty($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
            $metaChanges['password_updated'] = true;
            $revokeTokens = true;
        }

        if ($revokeTokens) {
            $user->tokens()->delete();
        }

        $user->update($data);

        PlatformAuditLog::create([
            'actor_user_id' => $request->user()->id,
            'action'        => 'user.update',
            'subject_type'  => 'user',
            'subject_id'    => $user->id,
            'spa_id'        => $user->spa_id,
            'meta'          => $metaChanges,
            'ip'            => $request->ip(),
            'created_at'    => now(),
        ]);

        return response()->json([
            'message' => 'User updated successfully',
            'user'    => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'role'      => $user->role,
                'is_active' => (bool) $user->is_active,
            ],
        ]);
    }
}
