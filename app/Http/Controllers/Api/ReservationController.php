<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Therapist;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
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
            'page'         => 'sometimes|integer|min:1',
            'per_page'     => 'sometimes|integer|min:1',
            'status'       => 'sometimes|nullable|string|in:pending,confirmed,completed,cancelled',
            'therapist_id' => 'sometimes|nullable|integer',
            'client_id'    => 'sometimes|nullable|integer',
            'from'         => 'sometimes|nullable|date',
            'to'           => 'sometimes|nullable|date|after_or_equal:from',
            'search'       => 'sometimes|nullable|string|max:255',
            'sort'         => 'sometimes|nullable|string|in:start_time,created_at,status,total_price',
            'direction'    => 'sometimes|nullable|string|in:asc,desc,ASC,DESC',
        ]);

        // Calendar support: enforce maximum 42-day span (inclusive) when both from and to are present on unpaginated queries
        if (! $request->has('page') && ! $request->has('per_page')) {
            if ($request->filled('from') && $request->filled('to')) {
                $fromDate = Carbon::parse($request->from)->startOfDay();
                $toDate = Carbon::parse($request->to)->startOfDay();
                if ($fromDate->diffInDays($toDate) + 1 > 42) {
                    return response()->json([
                        'message' => 'The date range may not exceed 42 days.',
                        'errors'  => ['date' => ['The date range may not exceed 42 days.']],
                    ], 422);
                }
            }
        }

        $query = Reservation::with(['client', 'service', 'therapist:id,name,color']);

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Therapist filter (tenant isolation check: foreign id or invalid id returns 422)
        if ($request->filled('therapist_id')) {
            $exists = Therapist::where('id', $request->therapist_id)
                ->where('spa_id', auth()->user()->spa_id)
                ->exists();

            if (! $exists) {
                return response()->json([
                    'message' => 'The selected therapist is invalid.',
                    'errors'  => ['therapist_id' => ['The selected therapist does not belong to your spa.']],
                ], 422);
            }

            $query->where('therapist_id', $request->therapist_id);
        }

        // Client filter (tenant isolation check: foreign id or invalid id returns 422)
        if ($request->filled('client_id')) {
            $exists = Client::where('id', $request->client_id)
                ->where('spa_id', auth()->user()->spa_id)
                ->exists();

            if (! $exists) {
                return response()->json([
                    'message' => 'The selected client is invalid.',
                    'errors'  => ['client_id' => ['The selected client does not belong to your spa.']],
                ], 422);
            }

            $query->where('client_id', $request->client_id);
        }

        // Date range on start_time (inclusive)
        if ($request->filled('from')) {
            $from = Carbon::parse($request->from);
            if (strlen(trim($request->from)) <= 10) {
                $from = $from->startOfDay();
            }
            $query->where('start_time', '>=', $from);
        }

        if ($request->filled('to')) {
            $to = Carbon::parse($request->to);
            if (strlen(trim($request->to)) <= 10) {
                $to = $to->endOfDay();
            }
            $query->where('start_time', '<=', $to);
        }

        // Search client name or phone with escaped LIKE wildcards
        if ($request->filled('search')) {
            $term = $this->escapeLike(trim($request->search));
            $pattern = "%{$term}%";
            $query->whereHas('client', function ($q) use ($pattern) {
                $q->whereRaw("full_name LIKE ? ESCAPE '\\'", [$pattern])
                  ->orWhereRaw("phone LIKE ? ESCAPE '\\'", [$pattern]);
            });
        }

        // Whitelisted sorting: default start_time desc, id desc tiebreaker
        $sort = $request->input('sort', 'start_time');
        $direction = strtolower($request->input('direction', 'desc'));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        $query->orderBy($sort, $direction)->orderBy('id', $direction);

        // Paginate only when ?page or ?per_page is present
        if ($request->has('page') || $request->has('per_page')) {
            $perPage = min((int) $request->input('per_page', 25), 100);
            return response()->json($query->paginate($perPage)->withQueryString());
        }

        // Legacy unpaginated response
        return response()->json($query->get())->header('X-Legacy-Unpaginated', '1');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id'   => [
                'required',
                Rule::exists('clients', 'id')->where('spa_id', auth()->user()->spa_id),
            ],
            'service_id'  => [
                'required',
                Rule::exists('services', 'id')->where('spa_id', auth()->user()->spa_id),
                function ($attribute, $value, $fail) {
                    $service = Service::find($value);
                    if ($service && ! $service->is_active) {
                        $fail('This service is not available.');
                    }
                },
            ],
            'therapist_id' => [
                'nullable',
                Rule::exists('therapists', 'id')->where('spa_id', auth()->user()->spa_id),
                function ($attribute, $value, $fail) {
                    if (! empty($value)) {
                        $therapist = Therapist::find($value);
                        if ($therapist && ! $therapist->is_active) {
                            $fail('This therapist is not available.');
                        }
                    }
                },
            ],
            'start_time'  => 'required|date',
            'end_time'    => 'required|date|after:start_time',
            'status'      => 'sometimes|string|in:pending,confirmed,completed,cancelled',
            'total_price' => 'required|numeric|min:0',
            'notes'       => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated) {
            if (! empty($validated['therapist_id'])) {
                // Lock the therapist record with lockForUpdate() inside a database transaction so that
                // concurrent requests attempting to book or update reservations for the same therapist
                // are serialized on this row lock. This prevents race conditions where two simultaneous
                // requests both check for overlapping reservations, find none, and both commit double-bookings.
                Therapist::where('id', $validated['therapist_id'])
                    ->where('spa_id', auth()->user()->spa_id)
                    ->lockForUpdate()
                    ->first();

                $conflict = $this->findTherapistOverlap(
                    auth()->user()->spa_id,
                    (int) $validated['therapist_id'],
                    $validated['start_time'],
                    $validated['end_time']
                );

                if ($conflict) {
                    $from = ($conflict->start_time instanceof Carbon ? $conflict->start_time : Carbon::parse($conflict->start_time))->format('H:i');
                    $to = ($conflict->end_time instanceof Carbon ? $conflict->end_time : Carbon::parse($conflict->end_time))->format('H:i');
                    $msg = "This therapist already has a reservation from {$from} to {$to}.";

                    return response()->json([
                        'message' => $msg,
                        'errors'  => ['therapist_id' => [$msg]],
                    ], 422);
                }
            }

            $reservation = Reservation::create($validated);

            return response()->json([
                'message'     => 'Reservation created successfully',
                'reservation' => $reservation->load(['client', 'service', 'therapist:id,name,color'])
            ], 201);
        });
    }

    public function show(Reservation $reservation)
    {
        return response()->json(
            $reservation->load(['client', 'service', 'therapist:id,name,color'])
        );
    }

    public function update(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'client_id'   => [
                'sometimes',
                Rule::exists('clients', 'id')->where('spa_id', auth()->user()->spa_id),
            ],
            'service_id'  => [
                'sometimes',
                Rule::exists('services', 'id')->where('spa_id', auth()->user()->spa_id),
                function ($attribute, $value, $fail) use ($reservation) {
                    if ((int) $value !== (int) $reservation->service_id) {
                        $service = Service::find($value);
                        if ($service && ! $service->is_active) {
                            $fail('This service is not available.');
                        }
                    }
                },
            ],
            'therapist_id' => [
                'sometimes',
                'nullable',
                Rule::exists('therapists', 'id')->where('spa_id', auth()->user()->spa_id),
                function ($attribute, $value, $fail) use ($reservation) {
                    if (! empty($value)) {
                        if ((int) $value !== (int) $reservation->therapist_id) {
                            $therapist = Therapist::find($value);
                            if ($therapist && ! $therapist->is_active) {
                                $fail('This therapist is not available.');
                            }
                        }
                    }
                },
            ],
            'start_time'  => 'sometimes|date',
            'end_time'    => 'sometimes|date|after:start_time',
            'status'      => 'sometimes|string|in:pending,confirmed,completed,cancelled',
            'total_price' => 'sometimes|numeric|min:0',
            'notes'       => 'nullable|string',
        ]);

        $effectiveTherapistId = array_key_exists('therapist_id', $validated)
            ? $validated['therapist_id']
            : $reservation->therapist_id;

        $effectiveStartTime = $validated['start_time'] ?? $reservation->start_time;
        $effectiveEndTime = $validated['end_time'] ?? $reservation->end_time;
        $effectiveStatus = $validated['status'] ?? $reservation->status;

        // Ensure end_time > start_time if either start_time or end_time is provided
        if (array_key_exists('start_time', $validated) || array_key_exists('end_time', $validated)) {
            if (Carbon::parse($effectiveEndTime)->lte(Carbon::parse($effectiveStartTime))) {
                return response()->json([
                    'message' => 'The end time field must be a date after start time.',
                    'errors'  => ['end_time' => ['The end time field must be a date after start time.']],
                ], 422);
            }
        }

        $isReactivating = $reservation->status === 'cancelled' && $effectiveStatus !== 'cancelled';
        $timeOrTherapistChanged = array_key_exists('start_time', $validated)
            || array_key_exists('end_time', $validated)
            || array_key_exists('therapist_id', $validated);

        // Check overlap if reactivating a cancelled reservation, or if time/therapist changed while active
        $shouldCheckOverlap = $effectiveStatus !== 'cancelled'
            && ! empty($effectiveTherapistId)
            && ($isReactivating || ($reservation->status !== 'cancelled' && $timeOrTherapistChanged));

        return DB::transaction(function () use ($reservation, $validated, $shouldCheckOverlap, $effectiveTherapistId, $effectiveStartTime, $effectiveEndTime) {
            if ($shouldCheckOverlap) {
                // Lock the therapist record with lockForUpdate() inside a database transaction so that
                // concurrent requests attempting to book or update reservations for the same therapist
                // are serialized on this row lock. This prevents race conditions where two simultaneous
                // requests both check for overlapping reservations, find none, and both commit double-bookings.
                Therapist::where('id', $effectiveTherapistId)
                    ->where('spa_id', auth()->user()->spa_id)
                    ->lockForUpdate()
                    ->first();

                $conflict = $this->findTherapistOverlap(
                    auth()->user()->spa_id,
                    (int) $effectiveTherapistId,
                    $effectiveStartTime,
                    $effectiveEndTime,
                    $reservation->id
                );

                if ($conflict) {
                    $from = ($conflict->start_time instanceof Carbon ? $conflict->start_time : Carbon::parse($conflict->start_time))->format('H:i');
                    $to = ($conflict->end_time instanceof Carbon ? $conflict->end_time : Carbon::parse($conflict->end_time))->format('H:i');
                    $msg = "This therapist already has a reservation from {$from} to {$to}.";

                    return response()->json([
                        'message' => $msg,
                        'errors'  => ['therapist_id' => [$msg]],
                    ], 422);
                }
            }

            $reservation->update($validated);

            return response()->json([
                'message'     => 'Reservation updated successfully',
                'reservation' => $reservation->load(['client', 'service', 'therapist:id,name,color'])
            ]);
        });
    }

    public function destroy(Reservation $reservation)
    {
        $reservation->delete();

        return response()->json([
            'message' => 'Reservation deleted successfully'
        ]);
    }

    private function findTherapistOverlap(int $spaId, int $therapistId, $startTime, $endTime, ?int $excludeReservationId = null): ?Reservation
    {
        $start = Carbon::parse($startTime)->format('Y-m-d H:i:s');
        $end = Carbon::parse($endTime)->format('Y-m-d H:i:s');

        return Reservation::where('spa_id', $spaId)
            ->where('therapist_id', $therapistId)
            ->where('status', '!=', 'cancelled')
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->when($excludeReservationId, fn ($q) => $q->where('id', '!=', $excludeReservationId))
            ->first();
    }
}
