<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Service::query()->orderBy('name');

        if (! $request->boolean('all')) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    $spaId = auth()->user()->spa_id;
                    $exists = Service::where('spa_id', $spaId)
                        ->whereRaw('LOWER(name) = ?', [strtolower(trim($value))])
                        ->exists();

                    if ($exists) {
                        $fail('A service with this name already exists.');
                    }
                },
            ],
            'duration_minutes' => 'required|integer|min:5|max:1440',
            'price'            => 'required|numeric|min:0',
            'description'      => 'nullable|string|max:2000',
            'is_active'        => 'sometimes|boolean',
        ]);

        $service = Service::create($validated);

        return response()->json([
            'message' => 'Service created successfully',
            'service' => $service,
        ], 201);
    }

    public function show(Service $service)
    {
        return response()->json($service);
    }

    public function update(Request $request, Service $service)
    {
        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($service) {
                    $spaId = auth()->user()->spa_id;
                    $exists = Service::where('spa_id', $spaId)
                        ->where('id', '!=', $service->id)
                        ->whereRaw('LOWER(name) = ?', [strtolower(trim($value))])
                        ->exists();

                    if ($exists) {
                        $fail('A service with this name already exists.');
                    }
                },
            ],
            'duration_minutes' => 'sometimes|integer|min:5|max:1440',
            'price'            => 'sometimes|numeric|min:0',
            'description'      => 'nullable|string|max:2000',
            'is_active'        => 'sometimes|boolean',
        ]);

        $service->update($validated);

        return response()->json([
            'message' => 'Service updated successfully',
            'service' => $service,
        ]);
    }

    public function destroy(Service $service)
    {
        $reservationCount = $service->reservations()->count();

        if ($reservationCount > 0) {
            return response()->json([
                'message' => "This service has {$reservationCount} reservation(s) and can't be deleted. Deactivate it instead.",
            ], 409);
        }

        $service->delete();

        return response()->json([
            'message' => 'Service deleted successfully',
        ]);
    }
}
