<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Reservation;
use Carbon\Carbon;
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
            'per_page'  => 'sometimes|integer|min:1',
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
            $perPage = min((int) $request->input('per_page', 25), 100);
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

    public function show(Request $request, Client $client)
    {
        $spaTz = config('yospa.timezone', 'Africa/Casablanca');
        $nowStr = now($spaTz)->format('Y-m-d H:i:s');

        // Computed with SQL aggregates scoped to the spa (via BelongsToSpa) and to this client
        $statsRow = Reservation::where('client_id', $client->id)
            ->selectRaw("
                COUNT(*) as total_reservations,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_count,
                COUNT(CASE WHEN start_time >= ? AND status != 'cancelled' THEN 1 END) as upcoming_count,
                MAX(CASE WHEN status = 'completed' THEN start_time END) as last_visit,
                SUM(CASE WHEN status = 'completed' THEN total_price ELSE 0 END) as total_spent
            ", [$nowStr])
            ->first();

        $canSeeRevenue = in_array($request->user()->role, ['owner', 'admin'], true);

        $totalSpent = null;
        if ($canSeeRevenue) {
            $totalSpent = round((float) ($statsRow?->total_spent ?? 0), 2);
        }

        $lastVisit = null;
        if ($statsRow?->last_visit) {
            $lastVisit = Carbon::parse($statsRow->last_visit)->format('Y-m-d\TH:i:s');
        }

        $stats = [
            'total_reservations' => (int) ($statsRow?->total_reservations ?? 0),
            'completed_count'    => (int) ($statsRow?->completed_count ?? 0),
            'cancelled_count'    => (int) ($statsRow?->cancelled_count ?? 0),
            'upcoming_count'     => (int) ($statsRow?->upcoming_count ?? 0),
            'last_visit'         => $lastVisit,
            'total_spent'        => $totalSpent,
        ];

        $clientData = $client->toArray();
        $clientData['stats'] = $stats;

        return response()->json($clientData);
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
