<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Spa;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SpaTestDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create a Test Spa
        $spa = Spa::create([
            'name' => 'Zenith Oasis Spa',
            'slug' => 'zenith-oasis-spa',
            'status' => 'active',
        ]);

        // 2. Create a User bound to this Spa
        $user = User::create([
            'name' => 'Youssef Admin',
            'email' => 'admin@yospa.test',
            'password' => Hash::make('password123'),
            'spa_id' => $spa->id,
            'role' => 'owner',
        ]);

        // 3. Create a Client for this Spa
        $client = Client::create([
            'spa_id' => $spa->id,
            'full_name' => 'Amine Test',
            'phone' => '+212600000000',
            'email' => 'amine@test.com',
            'notes' => 'Prefers deep tissue massage.',
        ]);

        // 4. Create a Service for this Spa
        $service = Service::create([
            'spa_id' => $spa->id,
            'name' => 'Relaxing Moroccan Hammam & Scrub',
            'description' => 'Traditional black soap scrub and eucalyptus steam.',
            'duration_minutes' => 60,
            'price' => 350.00,
            'is_active' => true,
        ]);

        // 5. Create a Reservation
        Reservation::create([
            'spa_id' => $spa->id,
            'client_id' => $client->id,
            'service_id' => $service->id,
            'start_time' => now()->addDay()->setHour(10)->setMinute(0),
            'end_time' => now()->addDay()->setHour(11)->setMinute(0),
            'status' => 'confirmed',
            'total_price' => 350.00,
            'notes' => 'First-time visitor.',
        ]);
    }
}
