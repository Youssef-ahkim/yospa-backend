<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function index()
    {
        return response()->json(
            Reservation::with(['client', 'service'])->latest('start_time')->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id'   => 'required|exists:clients,id',
            'service_id'  => 'required|exists:services,id',
            'start_time'  => 'required|date',
            'end_time'    => 'required|date|after:start_time',
            'status'      => 'sometimes|string|in:pending,confirmed,completed,cancelled',
            'total_price' => 'required|numeric|min:0',
            'notes'       => 'nullable|string',
        ]);

        $reservation = Reservation::create($validated);

        return response()->json([
            'message'     => 'Reservation created successfully',
            'reservation' => $reservation->load(['client', 'service'])
        ], 201);
    }

    public function show(Reservation $reservation)
    {
        return response()->json($reservation->load(['client', 'service']));
    }

    public function update(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'client_id'   => 'sometimes|exists:clients,id',
            'service_id'  => 'sometimes|exists:services,id',
            'start_time'  => 'sometimes|date',
            'end_time'    => 'sometimes|date|after:start_time',
            'status'      => 'sometimes|string|in:pending,confirmed,completed,cancelled',
            'total_price' => 'sometimes|numeric|min:0',
            'notes'       => 'nullable|string',
        ]);

        $reservation->update($validated);

        return response()->json([
            'message'     => 'Reservation updated successfully',
            'reservation' => $reservation->load(['client', 'service'])
        ]);
    }

    public function destroy(Reservation $reservation)
    {
        $reservation->delete();

        return response()->json([
            'message' => 'Reservation deleted successfully'
        ]);
    }
}
