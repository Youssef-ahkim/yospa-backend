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

        $today = now()->toDateString();

        // 1. Today's appointments (excluding cancelled)
        $todayReservationsCount = Reservation::whereDate('start_time', $today)
            ->where('status', '!=', 'cancelled')
            ->count();

        // 2. Upcoming reservations (start_time >= now, excluding cancelled)
        $upcomingCount = Reservation::where('start_time', '>=', now())
            ->where('status', '!=', 'cancelled')
            ->count();

        // 3. Revenue for completed appointments (date range if provided, or current month by default)
        $revenueQuery = Reservation::where('status', 'completed');

        if (! empty($validated['from'])) {
            $from = Carbon::parse($validated['from']);
            if (strlen(trim($validated['from'])) <= 10) {
                $from = $from->startOfDay();
            }
            $revenueQuery->where('start_time', '>=', $from);
        } else {
            $revenueQuery->where('start_time', '>=', now()->startOfMonth());
        }

        if (! empty($validated['to'])) {
            $to = Carbon::parse($validated['to']);
            if (strlen(trim($validated['to'])) <= 10) {
                $to = $to->endOfDay();
            }
            $revenueQuery->where('start_time', '<=', $to);
        } else {
            $revenueQuery->where('start_time', '<=', now()->endOfMonth());
        }

        $revenue = (float) ($revenueQuery->sum('total_price') ?? 0);

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
            'revenue'                  => round($revenue, 2),
            'counts_by_status'         => $countsByStatus,
            'total_clients'            => $totalClients,
            'total_reservations'       => $totalReservations,
        ]);
    }
}
