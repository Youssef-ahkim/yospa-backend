<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Spa;
use App\Models\User;
use Illuminate\Console\Command;

class ListSpasCommand extends Command
{
    protected $signature = 'yospa:list-spas';

    protected $description = 'List all registered spas with user, client, and reservation counts';

    public function handle(): int
    {
        $spas = Spa::orderBy('id')->get();

        if ($spas->isEmpty()) {
            $this->info('No spas found.');
            return 0;
        }

        $rows = $spas->map(function (Spa $spa) {
            $owners = User::where('spa_id', $spa->id)
                ->where('role', 'owner')
                ->pluck('email')
                ->implode(', ');

            $userCount = User::where('spa_id', $spa->id)->count();
            $clientCount = Client::withoutGlobalScopes()->where('spa_id', $spa->id)->count();
            $reservationCount = Reservation::withoutGlobalScopes()->where('spa_id', $spa->id)->count();

            return [
                'id'           => $spa->id,
                'name'         => $spa->name,
                'slug'         => $spa->slug,
                'status'       => $spa->status,
                'owners'       => $owners ?: 'None',
                'users'        => $userCount,
                'clients'      => $clientCount,
                'reservations' => $reservationCount,
            ];
        });

        $this->table(
            ['ID', 'Name', 'Slug', 'Status', 'Owner(s)', 'Users', 'Clients', 'Reservations'],
            $rows
        );

        return 0;
    }
}
