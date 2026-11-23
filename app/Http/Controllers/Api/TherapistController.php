<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Therapist;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TherapistController extends Controller
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
            'per_page'  => 'sometimes|integer|min:1|max:100',
            'all'       => 'sometimes|boolean',
            'search'    => 'sometimes|nullable|string|max:255',
            'sort'      => 'sometimes|nullable|string|in:name,created_at,email,phone',
            'direction' => 'sometimes|nullable|string|in:asc,desc,ASC,DESC',
        ]);

        $query = Therapist::query();

        if (! $request->boolean('all')) {
            $query->where('is_active', true);
        }

        if ($request->filled('search')) {
            $term = $this->escapeLike(trim($request->search));
            $pattern = "%{$term}%";
            $query->where(function ($q) use ($pattern) {
                $q->whereRaw("name LIKE ? ESCAPE '\\'", [$pattern])
                  ->orWhereRaw("email LIKE ? ESCAPE '\\'", [$pattern])
                  ->orWhereRaw("phone LIKE ? ESCAPE '\\'", [$pattern]);
            });
        }

        $sort = $request->input('sort', 'name');
        $direction = strtolower($request->input('direction', 'asc'));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        $query->orderBy($sort, $direction)->orderBy('id', $direction);

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = (int) $request->input('per_page', 25);
            return response()->json($query->paginate($perPage)->withQueryString());
        }

        return response()->json($query->get())->header('X-Legacy-Unpaginated', '1');
    }

    public function store(Request $request)
    {
        $spaId = $request->user()->spa_id;

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($spaId) {
                    $exists = Therapist::where('spa_id', $spaId)
                        ->whereRaw('LOWER(name) = ?', [strtolower(trim($value))])
                        ->exists();

                    if ($exists) {
                        $fail('A therapist with this name already exists.');
                    }
                },
            ],
            'phone'     => 'nullable|string|max:50',
            'email'     => 'nullable|email|max:255',
            'color'     => ['sometimes', 'string', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'notes'     => 'nullable|string|max:2000',
            'is_active' => 'sometimes|boolean',
            'user_id'   => [
                'nullable',
                Rule::exists('users', 'id')->where('spa_id', $spaId),
                function ($attribute, $value, $fail) {
                    if (! empty($value)) {
                        $exists = Therapist::where('user_id', $value)->exists();
                        if ($exists) {
                            $fail('This user is already linked to another therapist.');
                        }
                    }
                },
            ],
        ]);

        $therapist = Therapist::create($validated);

        return response()->json([
            'message'   => 'Therapist created successfully',
            'therapist' => $therapist,
        ], 201);
    }

    public function show(Therapist $therapist)
    {
        return response()->json($therapist);
    }

    public function update(Request $request, Therapist $therapist)
    {
        $spaId = $request->user()->spa_id;

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($spaId, $therapist) {
                    $exists = Therapist::where('spa_id', $spaId)
                        ->where('id', '!=', $therapist->id)
                        ->whereRaw('LOWER(name) = ?', [strtolower(trim($value))])
                        ->exists();

                    if ($exists) {
                        $fail('A therapist with this name already exists.');
                    }
                },
            ],
            'phone'     => 'nullable|string|max:50',
            'email'     => 'nullable|email|max:255',
            'color'     => ['sometimes', 'string', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'notes'     => 'nullable|string|max:2000',
            'is_active' => 'sometimes|boolean',
            'user_id'   => [
                'nullable',
                Rule::exists('users', 'id')->where('spa_id', $spaId),
                function ($attribute, $value, $fail) use ($therapist) {
                    if (! empty($value)) {
                        $exists = Therapist::where('user_id', $value)
                            ->where('id', '!=', $therapist->id)
                            ->exists();
                        if ($exists) {
                            $fail('This user is already linked to another therapist.');
                        }
                    }
                },
            ],
        ]);

        $therapist->update($validated);

        return response()->json([
            'message'   => 'Therapist updated successfully',
            'therapist' => $therapist,
        ]);
    }

    public function destroy(Therapist $therapist)
    {
        $reservationCount = $therapist->reservations()->count();

        if ($reservationCount > 0) {
            return response()->json([
                'message' => "This therapist has {$reservationCount} reservation(s) and can't be deleted. Deactivate them instead.",
            ], 409);
        }

        $therapist->delete();

        return response()->json([
            'message' => 'Therapist deleted successfully',
        ]);
    }
}
