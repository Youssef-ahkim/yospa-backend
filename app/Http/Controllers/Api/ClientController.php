<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index()
    {
        return response()->json(Client::latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'phone'     => 'required|string|max:50',
            'email'     => 'nullable|email|max:255',
            'notes'     => 'nullable|string',
        ]);

        $client = Client::create($validated);

        return response()->json([
            'message' => 'Client created successfully',
            'client'  => $client
        ], 201);
    }

    public function show(Client $client)
    {
        return response()->json($client);
    }

    public function update(Request $request, Client $client)
    {
        $validated = $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'phone'     => 'sometimes|string|max:50',
            'email'     => 'nullable|email|max:255',
            'notes'     => 'nullable|string',
        ]);

        $client->update($validated);

        return response()->json([
            'message' => 'Client updated successfully',
            'client'  => $client
        ]);
    }

    public function destroy(Client $client)
    {
        $client->delete();

        return response()->json([
            'message' => 'Client deleted successfully'
        ]);
    }
}
