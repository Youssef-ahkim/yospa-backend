<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    private function escapeLike(string $value, string $char = '\\'): string
    {
        return str_replace(
            [$char, '%', '_'],
            [$char.$char, $char.'%', $char.'_'],
            $value
        );
    }

    public function index(Request $request)
    {
        $request->validate([
            'page'      => 'sometimes|integer|min:1',
            'per_page'  => 'sometimes|integer|min:1',
            'all'       => 'sometimes|boolean',
            'role'      => 'sometimes|nullable|string|in:owner,admin,staff',
            'search'    => 'sometimes|nullable|string|max:255',
            'sort'      => 'sometimes|nullable|string|in:name,email,role,created_at',
            'direction' => 'sometimes|nullable|string|in:asc,desc,ASC,DESC',
        ]);

        $query = User::where('spa_id', $request->user()->spa_id)
            ->select('id', 'name', 'email', 'role', 'is_active');

        if (! $request->boolean('all')) {
            $query->where('is_active', true);
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('search')) {
            $term = $this->escapeLike(trim($request->search));
            $pattern = "%{$term}%";
            $query->where(function ($q) use ($pattern) {
                $q->whereRaw("name LIKE ? ESCAPE '\\'", [$pattern])
                  ->orWhereRaw("email LIKE ? ESCAPE '\\'", [$pattern]);
            });
        }

        $sort = $request->input('sort', 'name');
        $direction = strtolower($request->input('direction', 'asc'));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        $query->orderBy($sort, $direction)->orderBy('id', $direction);

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = min((int) $request->input('per_page', 25), 100);
            return response()->json($query->paginate($perPage)->withQueryString());
        }

        return response()->json($query->get())->header('X-Legacy-Unpaginated', '1');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'role'     => ['sometimes', 'string', Rule::in(['admin', 'staff'])],
        ], [
            'role.in' => 'Only the platform administrator can assign the owner role.',
        ]);

        $role = $validated['role'] ?? 'staff';

        // Admins can only create staff accounts
        if ($request->user()->role === 'admin' && $role !== 'staff') {
            return response()->json([
                'message' => 'Admins can only create staff accounts.',
            ], 403);
        }

        $user = User::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'password'  => Hash::make($validated['password']),
            'spa_id'    => $request->user()->spa_id,
            'role'      => $role,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Staff member created successfully',
            'staff'   => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'role'      => $user->role,
                'is_active' => $user->is_active,
            ],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $staff = User::where('spa_id', $request->user()->spa_id)->findOrFail($id);
        $currentUser = $request->user();

        // Admin safeguard: cannot modify owner or admin accounts
        if ($currentUser->role === 'admin' && in_array($staff->role, ['owner', 'admin'], true)) {
            return response()->json([
                'message' => 'Admins cannot modify owner or admin accounts.',
            ], 403);
        }

        // Admin safeguard: cannot promote users to owner or admin
        if ($currentUser->role === 'admin' && $request->has('role') && in_array($request->role, ['owner', 'admin'], true)) {
            return response()->json([
                'message' => 'Admins cannot promote users to owner or admin.',
            ], 403);
        }

        // Protect owners: a tenant user cannot change, deactivate, delete or reset the password of a user whose role is owner
        if ($staff->role === 'owner' && (int) $staff->id !== (int) $currentUser->id) {
            return response()->json([
                'message' => 'Unauthorized action.',
            ], 403);
        }

        // Protect owners: an owner's password cannot be reset via staff endpoint
        if ($staff->role === 'owner' && $request->filled('password')) {
            return response()->json([
                'message' => 'Unauthorized action.',
            ], 403);
        }

        // Self-modification safeguards
        if ((int) $staff->id === (int) $currentUser->id) {
            // Cannot demote yourself
            if ($request->has('role') && $request->role !== $staff->role) {
                return response()->json([
                    'message' => 'You cannot demote yourself.',
                ], 422);
            }

            // Cannot deactivate yourself
            if ($request->has('is_active') && ! $request->boolean('is_active')) {
                return response()->json([
                    'message' => 'You cannot deactivate your own account.',
                ], 422);
            }
        }

        // Last active owner safeguard
        if ($staff->role === 'owner') {
            $demoting = $request->has('role') && $request->role !== 'owner';
            $deactivating = $request->has('is_active') && ! $request->boolean('is_active');

            if ($demoting || $deactivating) {
                $otherActiveOwners = User::where('spa_id', $currentUser->spa_id)
                    ->where('role', 'owner')
                    ->where('is_active', true)
                    ->where('id', '!=', $staff->id)
                    ->count();

                if ($otherActiveOwners === 0) {
                    return response()->json([
                        'message' => 'Cannot deactivate or change role of the last active owner.',
                    ], 409);
                }
            }
        }

        $validated = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'email'     => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($staff->id)],
            'password'  => 'sometimes|string|min:8',
            'role'      => ['sometimes', 'string', Rule::in(['admin', 'staff'])],
            'is_active' => 'sometimes|boolean',
        ], [
            'role.in' => 'Only the platform administrator can assign the owner role.',
        ]);

        $data = [];

        if (array_key_exists('name', $validated)) {
            $data['name'] = $validated['name'];
        }

        if (array_key_exists('email', $validated)) {
            $data['email'] = $validated['email'];
        }

        if (array_key_exists('role', $validated)) {
            $data['role'] = $validated['role'];
        }

        if (array_key_exists('is_active', $validated)) {
            $data['is_active'] = $validated['is_active'];
            if (! $validated['is_active']) {
                $staff->tokens()->delete();
            }
        }

        if (! empty($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
            $staff->tokens()->delete();
        }

        $staff->update($data);

        return response()->json([
            'message' => 'Staff member updated successfully',
            'staff'   => [
                'id'        => $staff->id,
                'name'      => $staff->name,
                'email'     => $staff->email,
                'role'      => $staff->role,
                'is_active' => (bool) $staff->is_active,
            ],
        ]);
    }
}
