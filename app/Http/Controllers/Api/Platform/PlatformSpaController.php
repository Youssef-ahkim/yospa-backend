<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PlatformAuditLog;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Spa;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class PlatformSpaController extends Controller
{
    public function index(Request $request)
    {
        $query = Spa::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = strtolower($request->search);
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$search}%"])
                  ->orWhereHas('users', function ($uq) use ($search) {
                      $uq->where('role', 'owner')
                         ->whereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
                  });
            });
        }

        $spas = $query->orderBy('id', 'desc')->get();

        $data = $spas->map(function (Spa $spa) {
            $ownerEmails = User::where('spa_id', $spa->id)
                ->where('role', 'owner')
                ->pluck('email')
                ->values()
                ->all();

            return [
                'id'           => $spa->id,
                'name'         => $spa->name,
                'slug'         => $spa->slug,
                'status'       => $spa->status,
                'owner_emails' => $ownerEmails,
                'counts'       => [
                    'users'        => User::where('spa_id', $spa->id)->count(),
                    'clients'      => Client::withoutGlobalScopes()->where('spa_id', $spa->id)->count(),
                    'services'     => Service::withoutGlobalScopes()->where('spa_id', $spa->id)->count(),
                    'therapists'   => Therapist::withoutGlobalScopes()->where('spa_id', $spa->id)->count(),
                    'reservations' => Reservation::withoutGlobalScopes()->where('spa_id', $spa->id)->count(),
                ],
                'created_at'   => $spa->created_at?->toIso8601String(),
            ];
        });

        return response()->json($data);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'slug'           => 'nullable|string|max:255',
            'owner_name'     => 'required|string|max:255',
            'owner_email'    => 'required|email|max:255',
            'owner_password' => 'required|string|min:8',
        ]);

        if (User::whereRaw('LOWER(email) = ?', [strtolower($validated['owner_email'])])->exists()) {
            throw ValidationException::withMessages([
                'owner_email' => ["A user with email '{$validated['owner_email']}' already exists."],
            ]);
        }

        $baseSlug = ! empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['name']);
        $slug = $baseSlug;
        $counter = 1;
        while (Spa::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        DB::transaction(function () use ($validated, $slug, &$spa, &$owner, $request) {
            $spa = Spa::create([
                'name'   => $validated['name'],
                'slug'   => $slug,
                'status' => 'active',
            ]);

            $owner = User::create([
                'name'      => $validated['owner_name'],
                'email'     => $validated['owner_email'],
                'password'  => Hash::make($validated['owner_password']),
                'spa_id'    => $spa->id,
                'role'      => 'owner',
                'is_active' => true,
            ]);

            PlatformAuditLog::create([
                'actor_user_id' => $request->user()->id,
                'action'        => 'spa.create',
                'subject_type'  => 'spa',
                'subject_id'    => $spa->id,
                'spa_id'        => $spa->id,
                'meta'          => [
                    'name'        => $spa->name,
                    'slug'        => $spa->slug,
                    'owner_id'    => $owner->id,
                    'owner_email' => $owner->email,
                ],
                'ip'            => $request->ip(),
                'created_at'    => now(),
            ]);
        });

        return response()->json([
            'message' => 'Spa created successfully',
            'spa'     => $spa,
            'owner'   => [
                'id'        => $owner->id,
                'name'      => $owner->name,
                'email'     => $owner->email,
                'role'      => $owner->role,
                'is_active' => $owner->is_active,
            ],
        ], 201);
    }

    public function show($id)
    {
        $spa = Spa::findOrFail($id);

        $users = User::where('spa_id', $spa->id)
            ->select('id', 'name', 'email', 'role', 'is_active')
            ->orderBy('name')
            ->get();

        $counts = [
            'users'        => $users->count(),
            'clients'      => Client::withoutGlobalScopes()->where('spa_id', $spa->id)->count(),
            'services'     => Service::withoutGlobalScopes()->where('spa_id', $spa->id)->count(),
            'therapists'   => Therapist::withoutGlobalScopes()->where('spa_id', $spa->id)->count(),
            'reservations' => Reservation::withoutGlobalScopes()->where('spa_id', $spa->id)->count(),
        ];

        return response()->json([
            'spa'    => [
                'id'         => $spa->id,
                'name'       => $spa->name,
                'slug'       => $spa->slug,
                'status'     => $spa->status,
                'created_at' => $spa->created_at?->toIso8601String(),
                'updated_at' => $spa->updated_at?->toIso8601String(),
            ],
            'counts' => $counts,
            'users'  => $users,
        ]);
    }

    public function update(Request $request, $id)
    {
        $spa = Spa::findOrFail($id);

        $validated = $request->validate([
            'name'   => 'sometimes|string|max:255',
            'slug'   => ['sometimes', 'string', 'max:255', Rule::unique('spas', 'slug')->ignore($spa->id)],
            'status' => 'sometimes|string|in:active,suspended',
        ]);

        $data = [];
        $metaChanges = [];

        if (array_key_exists('name', $validated)) {
            $data['name'] = $validated['name'];
            $metaChanges['name'] = $validated['name'];
        }

        if (array_key_exists('slug', $validated)) {
            $data['slug'] = Str::slug($validated['slug']);
            $metaChanges['slug'] = $data['slug'];
        }

        if (array_key_exists('status', $validated)) {
            $data['status'] = $validated['status'];
            $metaChanges['status'] = $validated['status'];
        }

        $spa->update($data);

        PlatformAuditLog::create([
            'actor_user_id' => $request->user()->id,
            'action'        => 'spa.update',
            'subject_type'  => 'spa',
            'subject_id'    => $spa->id,
            'spa_id'        => $spa->id,
            'meta'          => $metaChanges,
            'ip'            => $request->ip(),
            'created_at'    => now(),
        ]);

        return response()->json([
            'message' => 'Spa updated successfully',
            'spa'     => $spa->fresh(),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $spa = Spa::findOrFail($id);

        $request->validate([
            'confirm_slug' => 'required|string',
        ]);

        if ($request->confirm_slug !== $spa->slug) {
            throw ValidationException::withMessages([
                'confirm_slug' => ['The confirmation slug does not match the spa slug.'],
            ]);
        }

        $reservationCount = Reservation::withoutGlobalScopes()->where('spa_id', $spa->id)->count();

        if ($spa->status !== 'suspended' || $reservationCount > 0) {
            return response()->json([
                'message' => 'Cannot delete spa. Spa must be suspended and have 0 reservations.',
            ], 409);
        }

        DB::transaction(function () use ($spa, $request) {
            $spaId = $spa->id;
            $spaSlug = $spa->slug;
            $spaName = $spa->name;

            // Capture counts at deletion time so audit log retains meaning
            $usersCount = User::where('spa_id', $spaId)->count();
            $clientsCount = Client::withoutGlobalScopes()->where('spa_id', $spaId)->count();
            $servicesCount = Service::withoutGlobalScopes()->where('spa_id', $spaId)->count();
            $therapistsCount = Therapist::withoutGlobalScopes()->where('spa_id', $spaId)->count();

            // Delete dependent records
            Therapist::withoutGlobalScopes()->where('spa_id', $spaId)->delete();
            Service::withoutGlobalScopes()->where('spa_id', $spaId)->delete();
            Client::withoutGlobalScopes()->where('spa_id', $spaId)->delete();

            $userIds = User::where('spa_id', $spaId)->pluck('id');
            PersonalAccessToken::whereIn('tokenable_id', $userIds)->delete();
            User::where('spa_id', $spaId)->delete();

            $spa->delete();

            PlatformAuditLog::create([
                'actor_user_id' => $request->user()->id,
                'action'        => 'spa.delete',
                'subject_type'  => 'spa',
                'subject_id'    => $spaId,
                'spa_id'        => null,
                'meta'          => [
                    'deleted_spa_id'   => $spaId,
                    'deleted_spa_slug' => $spaSlug,
                    'deleted_spa_name' => $spaName,
                    'counts'           => [
                        'users'      => $usersCount,
                        'clients'    => $clientsCount,
                        'services'   => $servicesCount,
                        'therapists' => $therapistsCount,
                    ],
                ],
                'ip'            => $request->ip(),
                'created_at'    => now(),
            ]);
        });

        return response()->json([
            'message' => 'Spa deleted successfully',
        ]);
    }

    public function storeUser(Request $request, $id)
    {
        $spa = Spa::findOrFail($id);

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255',
            'password' => 'required|string|min:8',
            'role'     => 'required|string|in:owner,admin,staff',
        ]);

        if (User::whereRaw('LOWER(email) = ?', [strtolower($validated['email'])])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['The email has already been taken.'],
            ]);
        }

        $user = User::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'password'  => Hash::make($validated['password']),
            'spa_id'    => $spa->id,
            'role'      => $validated['role'],
            'is_active' => true,
        ]);

        PlatformAuditLog::create([
            'actor_user_id' => $request->user()->id,
            'action'        => 'user.create',
            'subject_type'  => 'user',
            'subject_id'    => $user->id,
            'spa_id'        => $spa->id,
            'meta'          => [
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ],
            'ip'            => $request->ip(),
            'created_at'    => now(),
        ]);

        return response()->json([
            'message' => 'User created successfully',
            'user'    => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'role'      => $user->role,
                'is_active' => $user->is_active,
            ],
        ], 201);
    }
}
