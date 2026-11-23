<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Spa;
use App\Models\Therapist;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientStatsTest extends TestCase
{
    use RefreshDatabase;

    private Spa $spaA;
    private Spa $spaB;
    private User $ownerA;
    private User $adminA;
    private User $staffA;
    private User $ownerB;
    private Service $serviceA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spaA = Spa::create([
            'name'   => 'Spa Alpha',
            'slug'   => 'spa-alpha',
            'status' => 'active',
        ]);

        $this->spaB = Spa::create([
            'name'   => 'Spa Beta',
            'slug'   => 'spa-beta',
            'status' => 'active',
        ]);

        $this->ownerA = User::create([
            'name'      => 'Owner Alpha',
            'email'     => 'owner@alpha.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spaA->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->adminA = User::create([
            'name'      => 'Admin Alpha',
            'email'     => 'admin@alpha.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spaA->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $this->staffA = User::create([
            'name'      => 'Staff Alpha',
            'email'     => 'staff@alpha.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spaA->id,
            'role'      => 'staff',
            'is_active' => true,
        ]);

        $this->ownerB = User::create([
            'name'      => 'Owner Beta',
            'email'     => 'owner@beta.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spaB->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->serviceA = Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Deep Tissue Massage',
            'duration_minutes' => 60,
            'price'            => 100.00,
            'is_active'        => true,
        ]);
    }

    public function test_client_stats_with_many_reservations_and_role_permissions(): void
    {
        Sanctum::actingAs($this->ownerA);

        $client = Client::create([
            'spa_id'    => $this->spaA->id,
            'full_name' => 'Sophia Laurent',
            'phone'     => '0612345678',
            'email'     => 'sophia@example.com',
        ]);

        // Fix current time for consistent testing
        Carbon::setTestNow(Carbon::parse('2026-11-23 12:00:00', 'UTC'));

        // 15 completed reservations (10 in past, 5 recent, latest at 2026-11-20 14:00:00)
        // total_price: 15 * 100.00 = 1500.00
        for ($i = 1; $i <= 15; $i++) {
            $day = str_pad((string) (5 + $i), 2, '0', STR_PAD_LEFT);
            Reservation::create([
                'spa_id'      => $this->spaA->id,
                'client_id'   => $client->id,
                'service_id'  => $this->serviceA->id,
                'start_time'  => "2026-11-{$day} 14:00:00",
                'end_time'    => "2026-11-{$day} 15:00:00",
                'status'      => 'completed',
                'total_price' => 100.00,
            ]);
        }

        // 8 cancelled reservations
        for ($i = 1; $i <= 8; $i++) {
            Reservation::create([
                'spa_id'      => $this->spaA->id,
                'client_id'   => $client->id,
                'service_id'  => $this->serviceA->id,
                'start_time'  => "2026-11-25 10:00:00",
                'end_time'    => "2026-11-25 11:00:00",
                'status'      => 'cancelled',
                'total_price' => 100.00,
            ]);
        }

        // 7 upcoming reservations (start_time >= now, non-cancelled)
        for ($i = 1; $i <= 7; $i++) {
            $day = str_pad((string) (23 + $i), 2, '0', STR_PAD_LEFT);
            Reservation::create([
                'spa_id'      => $this->spaA->id,
                'client_id'   => $client->id,
                'service_id'  => $this->serviceA->id,
                'start_time'  => "2026-11-{$day} 16:00:00",
                'end_time'    => "2026-11-{$day} 17:00:00",
                'status'      => $i % 2 === 0 ? 'confirmed' : 'pending',
                'total_price' => 120.00,
            ]);
        }

        // 5 past pending/confirmed reservations (not completed, not cancelled, past)
        for ($i = 1; $i <= 5; $i++) {
            $day = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            Reservation::create([
                'spa_id'      => $this->spaA->id,
                'client_id'   => $client->id,
                'service_id'  => $this->serviceA->id,
                'start_time'  => "2026-11-{$day} 09:00:00",
                'end_time'    => "2026-11-{$day} 10:00:00",
                'status'      => 'confirmed',
                'total_price' => 80.00,
            ]);
        }

        // Total reservations created = 15 + 8 + 7 + 5 = 35

        // 1. Owner request (no Accept header)
        $resOwner = $this->get("/api/clients/{$client->id}");
        $resOwner->assertStatus(200);

        $jsonOwner = $resOwner->json();
        $this->assertEquals('Sophia Laurent', $jsonOwner['full_name']);
        $this->assertArrayHasKey('stats', $jsonOwner);

        $stats = $jsonOwner['stats'];
        $this->assertEquals(35, $stats['total_reservations']);
        $this->assertEquals(15, $stats['completed_count']);
        $this->assertEquals(8, $stats['cancelled_count']);
        $this->assertEquals(7, $stats['upcoming_count']);
        $this->assertEquals('2026-11-20T14:00:00', $stats['last_visit']);
        $this->assertEquals(1500.00, $stats['total_spent']);

        // 2. Admin request
        Sanctum::actingAs($this->adminA);
        $resAdmin = $this->get("/api/clients/{$client->id}");
        $resAdmin->assertStatus(200);
        $this->assertEquals(1500.00, $resAdmin->json('stats.total_spent'));
        $this->assertEquals(35, $resAdmin->json('stats.total_reservations'));

        // 3. Staff request (total_spent MUST be null)
        Sanctum::actingAs($this->staffA);
        $resStaff = $this->get("/api/clients/{$client->id}");
        $resStaff->assertStatus(200);

        $staffStats = $resStaff->json('stats');
        $this->assertNull($staffStats['total_spent']);
        $this->assertEquals(35, $staffStats['total_reservations']);
        $this->assertEquals(15, $staffStats['completed_count']);
        $this->assertEquals(8, $staffStats['cancelled_count']);
        $this->assertEquals(7, $staffStats['upcoming_count']);
        $this->assertEquals('2026-11-20T14:00:00', $staffStats['last_visit']);
    }

    public function test_client_stats_query_count_is_constant_no_n_plus_one(): void
    {
        Sanctum::actingAs($this->ownerA);

        $clientSmall = Client::create([
            'spa_id'    => $this->spaA->id,
            'full_name' => 'Small Client',
            'phone'     => '111111',
        ]);
        for ($i = 1; $i <= 2; $i++) {
            Reservation::create([
                'spa_id'      => $this->spaA->id,
                'client_id'   => $clientSmall->id,
                'service_id'  => $this->serviceA->id,
                'start_time'  => "2026-11-1{$i} 10:00:00",
                'end_time'    => "2026-11-1{$i} 11:00:00",
                'status'      => 'completed',
                'total_price' => 100.00,
            ]);
        }

        $clientLarge = Client::create([
            'spa_id'    => $this->spaA->id,
            'full_name' => 'Large Client',
            'phone'     => '222222',
        ]);
        for ($i = 1; $i <= 35; $i++) {
            Reservation::create([
                'spa_id'      => $this->spaA->id,
                'client_id'   => $clientLarge->id,
                'service_id'  => $this->serviceA->id,
                'start_time'  => "2026-11-10 10:00:00",
                'end_time'    => "2026-11-10 11:00:00",
                'status'      => 'completed',
                'total_price' => 50.00,
            ]);
        }

        // Measure queries for client with 2 reservations
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->get("/api/clients/{$clientSmall->id}")->assertStatus(200);
        $queriesSmall = $queries;

        // Measure queries for client with 35 reservations
        $queries = 0;
        $this->get("/api/clients/{$clientLarge->id}")->assertStatus(200);
        $queriesLarge = $queries;

        $this->assertGreaterThan(0, $queriesSmall);
        $this->assertEquals($queriesSmall, $queriesLarge, 'Query count must remain constant regardless of reservation volume');
    }

    public function test_client_of_another_spa_returns_404_and_never_leaks(): void
    {
        // Client belongs to Spa B
        $clientB = Client::create([
            'spa_id'    => $this->spaB->id,
            'full_name' => 'Secret Beta Client',
            'phone'     => '999999',
            'email'     => 'secret@beta.test',
        ]);

        Reservation::create([
            'spa_id'      => $this->spaB->id,
            'client_id'   => $clientB->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => '2026-11-20 10:00:00',
            'end_time'    => '2026-11-20 11:00:00',
            'status'      => 'completed',
            'total_price' => 500.00,
        ]);

        // Caller is from Spa A
        Sanctum::actingAs($this->ownerA);
        $response = $this->get("/api/clients/{$clientB->id}");

        $response->assertStatus(404);
        $this->assertStringNotContainsString('Secret Beta Client', $response->getContent());
        $this->assertStringNotContainsString('secret@beta.test', $response->getContent());
    }

    public function test_client_stats_upcoming_respects_spa_timezone_boundary(): void
    {
        Sanctum::actingAs($this->ownerA);

        $client = Client::create([
            'spa_id'    => $this->spaA->id,
            'full_name' => 'Timezone Client',
            'phone'     => '555555',
        ]);

        // Reservation scheduled at 2026-11-24 15:30:00 local time
        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $client->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => '2026-11-24 15:30:00',
            'end_time'    => '2026-11-24 16:30:00',
            'status'      => 'confirmed',
            'total_price' => 150.00,
        ]);

        // Cancelled reservation in the future - must NOT be counted as upcoming
        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $client->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => '2026-11-24 18:00:00',
            'end_time'    => '2026-11-24 19:00:00',
            'status'      => 'cancelled',
            'total_price' => 200.00,
        ]);

        // In Africa/Casablanca (UTC+1):
        // 13:00 UTC = 14:00 local time -> 15:30 is in the future (upcoming = 1)
        Carbon::setTestNow(Carbon::parse('2026-11-24 13:00:00', 'UTC'));
        $resAt14 = $this->get("/api/clients/{$client->id}");
        $resAt14->assertStatus(200);
        $this->assertEquals(1, $resAt14->json('stats.upcoming_count'));

        // 15:00 UTC = 16:00 local time -> 15:30 is in the past (upcoming = 0)
        Carbon::setTestNow(Carbon::parse('2026-11-24 15:00:00', 'UTC'));
        $resAt16 = $this->get("/api/clients/{$client->id}");
        $resAt16->assertStatus(200);
        $this->assertEquals(0, $resAt16->json('stats.upcoming_count'));

        // Reset Carbon test now
        Carbon::setTestNow();
    }
}
