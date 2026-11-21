<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Reservation;
use App\Models\Spa;
use App\Models\User;
use Illuminate\Http\Request;

class PlatformStatsController extends Controller
{
    public function index(Request $request)
    {
        $activeSpas = Spa::where('status', 'active')->count();
        $suspendedSpas = Spa::where('status', 'suspended')->count();
        $totalSpas = $activeSpas + $suspendedSpas;

        $totalUsers = User::whereNotNull('spa_id')->count();
        $totalClients = Client::withoutGlobalScopes()->count();
        $totalReservations = Reservation::withoutGlobalScopes()->count();

        return response()->json([
            'spas' => [
                'total'     => $totalSpas,
                'active'    => $activeSpas,
                'suspended' => $suspendedSpas,
            ],
            'users'        => $totalUsers,
            'clients'      => $totalClients,
            'reservations' => $totalReservations,
        ]);
    }
}
