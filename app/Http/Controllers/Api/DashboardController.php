<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    private function resolveRangeBounds(string $range, Carbon $nowSpa): array
    {
        switch ($range) {
            case '1d':
                $from = $nowSpa->copy()->startOfDay();
                $to = $nowSpa->copy()->endOfDay();
                break;
            case '7d':
                $from = $nowSpa->copy()->subDays(6)->startOfDay();
                $to = $nowSpa->copy()->endOfDay();
                break;
            case '30d':
                $from = $nowSpa->copy()->subDays(29)->startOfDay();
                $to = $nowSpa->copy()->endOfDay();
                break;
            case '12m':
                $from = $nowSpa->copy()->subMonths(11)->startOfMonth()->startOfDay();
                $to = $nowSpa->copy()->endOfMonth()->endOfDay();
                break;
            default:
                throw new \InvalidArgumentException("Invalid range: {$range}");
        }

        return [$from, $to];
    }

    public function salesReport(Request $request)
    {
        $validated = $request->validate([
            'range' => 'required|string|in:1d,7d,30d,12m',
        ]);
        $range = $validated['range'];

        $spaTz = config('yospa.timezone', 'Africa/Casablanca');
        $nowSpa = now($spaTz);
        [$from, $to] = $this->resolveRangeBounds($range, $nowSpa);

        $fromStr = $from->format('Y-m-d H:i:s');
        $toStr = $to->format('Y-m-d H:i:s');

        $isSqlite = DB::getDriverName() === 'sqlite';

        // Pre-generate buckets to guarantee zero gaps
        $bucketTemplates = [];
        if ($range === '1d') {
            $timeExpr = $isSqlite
                ? "strftime('%Y-%m-%d %H:00:00', start_time)"
                : "to_char(start_time, 'YYYY-MM-DD HH24:00:00')";

            for ($h = 0; $h < 24; $h++) {
                $bucketStart = $from->copy()->addHours($h);
                $key = $bucketStart->format('Y-m-d H:00:00');
                $bucketTemplates[] = [
                    'key'   => $key,
                    'label' => $bucketStart->format('H:00'),
                    'start' => $key,
                ];
            }
        } elseif ($range === '12m') {
            $timeExpr = $isSqlite
                ? "strftime('%Y-%m', start_time)"
                : "to_char(start_time, 'YYYY-MM')";

            for ($m = 0; $m < 12; $m++) {
                $bucketMonth = $from->copy()->addMonths($m);
                $key = $bucketMonth->format('Y-m');
                $bucketTemplates[] = [
                    'key'   => $key,
                    'label' => $bucketMonth->format('M Y'),
                    'start' => $bucketMonth->copy()->startOfMonth()->format('Y-m-d 00:00:00'),
                ];
            }
        } else {
            // 7d or 30d (daily)
            $timeExpr = $isSqlite
                ? "strftime('%Y-%m-%d', start_time)"
                : "to_char(start_time, 'YYYY-MM-DD')";

            $daysCount = $range === '7d' ? 7 : 30;
            for ($d = 0; $d < $daysCount; $d++) {
                $bucketDate = $from->copy()->addDays($d);
                $key = $bucketDate->format('Y-m-d');
                $bucketTemplates[] = [
                    'key'   => $key,
                    'label' => $bucketDate->format('M j'),
                    'start' => $bucketDate->format('Y-m-d 00:00:00'),
                ];
            }
        }

        // Query grouped by time bucket
        $rows = Reservation::query()
            ->where('start_time', '>=', $fromStr)
            ->where('start_time', '<=', $toStr)
            ->selectRaw("
                {$timeExpr} as bucket_key,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
                COUNT(CASE WHEN status != 'cancelled' THEN 1 END) as bookings,
                SUM(CASE WHEN status = 'completed' THEN total_price ELSE 0 END) as revenue
            ")
            ->groupByRaw($timeExpr)
            ->get()
            ->keyBy('bucket_key');

        $canSeeRevenue = in_array($request->user()->role, ['owner', 'admin'], true);

        $buckets = [];
        $totalRevenue = 0.0;
        $totalCompletedCount = 0;
        $totalBookings = 0;

        foreach ($bucketTemplates as $tmpl) {
            $row = $rows->get($tmpl['key']);
            $completedCount = (int) ($row?->completed_count ?? 0);
            $bookings = (int) ($row?->bookings ?? 0);
            $rev = (float) ($row?->revenue ?? 0);

            $totalCompletedCount += $completedCount;
            $totalBookings += $bookings;
            $totalRevenue += $rev;

            $buckets[] = [
                'label'           => $tmpl['label'],
                'start'           => $tmpl['start'],
                'revenue'         => $canSeeRevenue ? round($rev, 2) : null,
                'completed_count' => $completedCount,
                'bookings'        => $bookings,
            ];
        }

        $totals = [
            'revenue'         => $canSeeRevenue ? round($totalRevenue, 2) : null,
            'completed_count' => $totalCompletedCount,
            'bookings'        => $totalBookings,
        ];

        return response()->json([
            'range'   => $range,
            'from'    => $fromStr,
            'to'      => $toStr,
            'now'     => $nowSpa->format('Y-m-d H:i:s'),
            'buckets' => $buckets,
            'totals'  => $totals,
        ]);
    }

    public function bestSellers(Request $request)
    {
        $validated = $request->validate([
            'range' => 'required|string|in:1d,7d,30d,12m',
        ]);
        $range = $validated['range'];

        $spaTz = config('yospa.timezone', 'Africa/Casablanca');
        $nowSpa = now($spaTz);
        [$from, $to] = $this->resolveRangeBounds($range, $nowSpa);

        $fromStr = $from->format('Y-m-d H:i:s');
        $toStr = $to->format('Y-m-d H:i:s');

        $canSeeRevenue = in_array($request->user()->role, ['owner', 'admin'], true);

        $bestSellers = Reservation::query()
            ->join('services', function ($join) {
                $join->on('services.id', '=', 'reservations.service_id')
                     ->on('services.spa_id', '=', 'reservations.spa_id');
            })
            ->where('reservations.status', 'completed')
            ->where('reservations.start_time', '>=', $fromStr)
            ->where('reservations.start_time', '<=', $toStr)
            ->selectRaw('
                reservations.service_id,
                services.name,
                COUNT(*) as count,
                SUM(reservations.total_price) as revenue
            ')
            ->groupBy('reservations.service_id', 'services.name')
            ->orderByDesc('count')
            ->orderBy('services.name', 'asc')
            ->orderBy('reservations.service_id', 'asc')
            ->limit(5)
            ->get()
            ->map(function ($row) use ($canSeeRevenue) {
                return [
                    'service_id' => (int) $row->service_id,
                    'name'       => (string) $row->name,
                    'count'      => (int) $row->count,
                    'revenue'    => $canSeeRevenue ? round((float) $row->revenue, 2) : null,
                ];
            });

        return response()->json($bestSellers);
    }
}
