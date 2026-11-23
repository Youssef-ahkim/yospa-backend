<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;

class ClientController extends Controller
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
            'search'    => 'sometimes|nullable|string|max:255',
            'sort'      => 'sometimes|nullable|string|in:full_name,created_at,phone,email',
            'direction' => 'sometimes|nullable|string|in:asc,desc,ASC,DESC',
        ]);

        $query = Client::query();

        if ($request->filled('search')) {
            $term = $this->escapeLike(trim($request->search));
            $pattern = "%{$term}%";
            $query->where(function ($q) use ($pattern) {
                $q->whereRaw("full_name LIKE ? ESCAPE '\\'", [$pattern])
                  ->orWhereRaw("phone LIKE ? ESCAPE '\\'", [$pattern])
                  ->orWhereRaw("email LIKE ? ESCAPE '\\'", [$pattern]);
            });
        }

        // Stable ordering: full_name asc, id asc tiebreaker (or specified sort)
        $sort = $request->input('sort', 'full_name');
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
