<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Therapist;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TherapistController extends Controller
{
    public function index(Request $request)
    {
        $query = Therapist::query()->orderBy('name');

        if (! $request->boolean('all')) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
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
