<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        $validated = $request->validate([
            'from' => 'sometimes|nullable|date',
            'to'   => 'sometimes|nullable|date|after_or_equal:from',
        ]);

        $spaTz = config('yospa.timezone', 'Africa/Casablanca');
        $nowSpa = now($spaTz);
        $todayStr = $nowSpa->toDateString();
        $nowStr = $nowSpa->format('Y-m-d H:i:s');

        // 1. Today's appointments (excluding cancelled)
        $todayReservationsCount = Reservation::whereDate('start_time', $todayStr)
            ->where('status', '!=', 'cancelled')
            ->count();

        // 2. Upcoming reservations (start_time >= now, excluding cancelled)
        $upcomingCount = Reservation::where('start_time', '>=', $nowStr)
            ->where('status', '!=', 'cancelled')
            ->count();

        // 3. Revenue for completed appointments (restricted to owner and admin)
        $canSeeRevenue = in_array($request->user()->role, ['owner', 'admin'], true);
        $revenue = null;

        if ($canSeeRevenue) {
            $revenueQuery = Reservation::where('status', 'completed');

            if (! empty($validated['from'])) {
                $from = Carbon::parse($validated['from']);
                if (strlen(trim($validated['from'])) <= 10) {
                    $from = $from->startOfDay();
                }
                $revenueQuery->where('start_time', '>=', $from->format('Y-m-d H:i:s'));
            } else {
                $revenueQuery->where('start_time', '>=', $nowSpa->copy()->startOfMonth()->format('Y-m-d H:i:s'));
            }

            if (! empty($validated['to'])) {
                $to = Carbon::parse($validated['to']);
                if (strlen(trim($validated['to'])) <= 10) {
                    $to = $to->endOfDay();
                }
                $revenueQuery->where('start_time', '<=', $to->format('Y-m-d H:i:s'));
            } else {
                $revenueQuery->where('start_time', '<=', $nowSpa->copy()->endOfMonth()->format('Y-m-d H:i:s'));
            }

            $revenue = round((float) ($revenueQuery->sum('total_price') ?? 0), 2);
        }

        // 4. Counts by status
        $rawStatusCounts = Reservation::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $countsByStatus = [
            'pending'   => (int) ($rawStatusCounts['pending'] ?? 0),
            'confirmed' => (int) ($rawStatusCounts['confirmed'] ?? 0),
            'completed' => (int) ($rawStatusCounts['completed'] ?? 0),
            'cancelled' => (int) ($rawStatusCounts['cancelled'] ?? 0),
        ];

        // 5. Total counts
        $totalClients = Client::count();
        $totalReservations = Reservation::count();

        return response()->json([
            'today_reservations_count' => $todayReservationsCount,
            'upcoming_count'           => $upcomingCount,
            'revenue'                  => $revenue !== null ? round($revenue, 2) : null,
            'counts_by_status'         => $countsByStatus,
            'total_clients'            => $totalClients,
            'total_reservations'       => $totalReservations,
        ]);
    }
}
