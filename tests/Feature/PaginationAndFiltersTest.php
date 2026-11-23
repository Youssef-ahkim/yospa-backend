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

class PaginationAndFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Spa $spaA;
    private Spa $spaB;
    private User $ownerA;
    private User $ownerB;
    private Service $serviceA;
    private Therapist $therapistA1;
    private Therapist $therapistA2;
    private Therapist $therapistB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spaA = Spa::create(['name' => 'Spa Alpha', 'slug' => 'spa-alpha', 'status' => 'active']);
        $this->spaB = Spa::create(['name' => 'Spa Beta', 'slug' => 'spa-beta', 'status' => 'active']);

        $this->ownerA = User::create([
            'name'      => 'Alice Owner',
            'email'     => 'alice@alpha.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spaA->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->ownerB = User::create([
            'name'      => 'Bob Owner',
            'email'     => 'bob@beta.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spaB->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->serviceA = Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Signature Massage',
            'duration_minutes' => 60,
            'price'            => 150.00,
            'is_active'        => true,
        ]);

        $this->therapistA1 = Therapist::create([
            'spa_id'    => $this->spaA->id,
            'name'      => 'Therapist Alice',
            'is_active' => true,
        ]);

        $this->therapistA2 = Therapist::create([
            'spa_id'    => $this->spaA->id,
            'name'      => 'Therapist Amber',
            'is_active' => false,
        ]);

        $this->therapistB = Therapist::create([
            'spa_id'    => $this->spaB->id,
            'name'      => 'Therapist Bob',
            'is_active' => true,
        ]);
    }

    public function test_legacy_unpaginated_returns_flat_array_with_header(): void
    {
        Sanctum::actingAs($this->ownerA);

        Client::create([
            'spa_id'    => $this->spaA->id,
            'full_name' => 'Client One',
            'phone'     => '111111111',
        ]);

        $response = $this->get('/api/clients');

        $response->assertStatus(200);
        $response->assertHeader('X-Legacy-Unpaginated', '1');
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('data', $data);
        $this->assertCount(1, $data);
    }

    public function test_reservations_pagination_meta_and_page_size_cap(): void
    {
        Sanctum::actingAs($this->ownerA);

        $client = Client::create([
            'spa_id'    => $this->spaA->id,
            'full_name' => 'Regular Client',
            'phone'     => '123456789',
        ]);

        for ($i = 1; $i <= 30; $i++) {
            Reservation::create([
                'spa_id'       => $this->spaA->id,
                'client_id'    => $client->id,
                'service_id'   => $this->serviceA->id,
                'therapist_id' => $this->therapistA1->id,
                'start_time'   => now()->addDays($i)->format('Y-m-d 10:00:00'),
                'end_time'     => now()->addDays($i)->format('Y-m-d 11:00:00'),
                'status'       => 'confirmed',
                'total_price'  => 150.00,
            ]);
        }

        // Default pagination: per_page 25
        $res = $this->get('/api/reservations?page=1');
        $res->assertStatus(200);
        $res->assertHeaderMissing('X-Legacy-Unpaginated');
        $json = $res->json();
        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('current_page', $json);
        $this->assertArrayHasKey('last_page', $json);
        $this->assertArrayHasKey('total', $json);
        $this->assertEquals(25, count($json['data']));
        $this->assertEquals(30, $json['total']);
        $this->assertEquals(2, $json['last_page']);

        // Custom per_page: 10
        $res10 = $this->get('/api/reservations?page=1&per_page=10');
        $res10->assertStatus(200);
        $this->assertCount(10, $res10->json('data'));

        // Max cap: per_page > 100 returns 422
        $resOver = $this->get('/api/reservations?per_page=150');
        $resOver->assertStatus(422);
    }

    public function test_reservations_status_filter(): void
    {
        Sanctum::actingAs($this->ownerA);

        $client = Client::create(['spa_id' => $this->spaA->id, 'full_name' => 'Status Client', 'phone' => '123']);

        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $client->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => now()->addHour(),
            'end_time'    => now()->addHours(2),
            'status'      => 'pending',
            'total_price' => 150,
        ]);

        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $client->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => now()->addHours(3),
            'end_time'    => now()->addHours(4),
            'status'      => 'completed',
            'total_price' => 150,
        ]);

        $res = $this->get('/api/reservations?status=pending&page=1');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals('pending', $res->json('data.0.status'));
    }

    public function test_reservations_therapist_and_client_filters_with_tenant_isolation(): void
    {
        Sanctum::actingAs($this->ownerA);

        $clientA = Client::create(['spa_id' => $this->spaA->id, 'full_name' => 'Client A', 'phone' => '111']);
        $clientB = Client::create(['spa_id' => $this->spaB->id, 'full_name' => 'Client B', 'phone' => '222']);

        $resA = Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => now()->addHour(),
            'end_time'     => now()->addHours(2),
            'status'       => 'confirmed',
            'total_price'  => 150,
        ]);

        // Filter with own therapist succeeds
        $resOwn = $this->get("/api/reservations?therapist_id={$this->therapistA1->id}&page=1");
        $resOwn->assertStatus(200);
        $this->assertCount(1, $resOwn->json('data'));

        // Filter with foreign therapist returns 422
        $resForeign = $this->get("/api/reservations?therapist_id={$this->therapistB->id}&page=1");
        $resForeign->assertStatus(422);
        $resForeign->assertJsonValidationErrors(['therapist_id']);

        // Filter with foreign client returns 422
        $resForeignClient = $this->get("/api/reservations?client_id={$clientB->id}&page=1");
        $resForeignClient->assertStatus(422);
        $resForeignClient->assertJsonValidationErrors(['client_id']);
    }

    public function test_reservations_date_range_inclusive_edges(): void
    {
        Sanctum::actingAs($this->ownerA);

        $client = Client::create(['spa_id' => $this->spaA->id, 'full_name' => 'Edge Client', 'phone' => '123']);

        $targetDate = '2026-12-15';

        // Appointment at the beginning of target date
        $resEarly = Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $client->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => "{$targetDate} 08:00:00",
            'end_time'    => "{$targetDate} 09:00:00",
            'status'      => 'confirmed',
            'total_price' => 150,
        ]);

        // Appointment at the end of target date
        $resLate = Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $client->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => "{$targetDate} 21:30:00",
            'end_time'    => "{$targetDate} 22:30:00",
            'status'      => 'confirmed',
            'total_price' => 150,
        ]);

        // Appointment on next day
        $resNextDay = Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $client->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => '2026-12-16 10:00:00',
            'end_time'    => '2026-12-16 11:00:00',
            'status'      => 'confirmed',
            'total_price' => 150,
        ]);

        // Filtering for exactly targetDate inclusive
        $res = $this->get("/api/reservations?from={$targetDate}&to={$targetDate}&page=1");
        $res->assertStatus(200);
        $ids = collect($res->json('data'))->pluck('id');
        $this->assertContains($resEarly->id, $ids);
        $this->assertContains($resLate->id, $ids);
        $this->assertNotContains($resNextDay->id, $ids);

        // Invalid date range (to before from) returns 422
        $invalid = $this->get('/api/reservations?from=2026-12-20&to=2026-12-10&page=1');
        $invalid->assertStatus(422);
    }

    public function test_search_with_wildcards_escaped(): void
    {
        Sanctum::actingAs($this->ownerA);

        $c1 = Client::create(['spa_id' => $this->spaA->id, 'full_name' => '100% Pure', 'phone' => '111']);
        $c2 = Client::create(['spa_id' => $this->spaA->id, 'full_name' => 'Pure Regular', 'phone' => '222']);
        $c3 = Client::create(['spa_id' => $this->spaA->id, 'full_name' => 'User_Underscore', 'phone' => '333']);
        $c4 = Client::create(['spa_id' => $this->spaA->id, 'full_name' => 'User Regular', 'phone' => '444']);

        // Searching '%' should only return '100% Pure', not everything
        $resPercent = $this->get('/api/clients?search=%&page=1');
        $resPercent->assertStatus(200);
        $names = collect($resPercent->json('data'))->pluck('full_name');
        $this->assertContains('100% Pure', $names);
        $this->assertNotContains('Pure Regular', $names);

        // Searching '_' should only return 'User_Underscore'
        $resUnderscore = $this->get('/api/clients?search=_&page=1');
        $resUnderscore->assertStatus(200);
        $namesU = collect($resUnderscore->json('data'))->pluck('full_name');
        $this->assertContains('User_Underscore', $namesU);
        $this->assertNotContains('User Regular', $namesU);
    }

    public function test_whitelisted_sorting_and_stable_ordering_across_pages(): void
    {
        Sanctum::actingAs($this->ownerA);

        for ($i = 1; $i <= 10; $i++) {
            Client::create([
                'spa_id'    => $this->spaA->id,
                'full_name' => sprintf('Client %02d', $i),
                'phone'     => (string) (1000 + $i),
            ]);
        }

        // Page 1 with per_page=5
        $p1 = $this->get('/api/clients?page=1&per_page=5&sort=full_name&direction=asc');
        $p1->assertStatus(200);
        $idsP1 = collect($p1->json('data'))->pluck('id');

        // Page 2 with per_page=5
        $p2 = $this->get('/api/clients?page=2&per_page=5&sort=full_name&direction=asc');
        $p2->assertStatus(200);
        $idsP2 = collect($p2->json('data'))->pluck('id');

        $this->assertCount(5, $idsP1);
        $this->assertCount(5, $idsP2);
        // Stable ordering: no overlap between pages
        $this->assertEmpty($idsP1->intersect($idsP2));

        // Invalid sort column returns 422
        $invalid = $this->get('/api/clients?sort=nonexistent&page=1');
        $invalid->assertStatus(422);
    }

    public function test_reservations_query_count_stays_constant_across_page_sizes(): void
    {
        Sanctum::actingAs($this->ownerA);

        $client = Client::create(['spa_id' => $this->spaA->id, 'full_name' => 'Query Client', 'phone' => '123']);

        for ($i = 1; $i <= 10; $i++) {
            Reservation::create([
                'spa_id'       => $this->spaA->id,
                'client_id'    => $client->id,
                'service_id'   => $this->serviceA->id,
                'therapist_id' => $this->therapistA1->id,
                'start_time'   => now()->addDays($i)->format('Y-m-d 10:00:00'),
                'end_time'     => now()->addDays($i)->format('Y-m-d 11:00:00'),
                'status'       => 'confirmed',
                'total_price'  => 150.00,
            ]);
        }

        // Measure query count for per_page = 2 vs per_page = 8
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->get('/api/reservations?page=1&per_page=2')->assertStatus(200);
        $queriesPage2 = $queries;

        $queries = 0;
        $this->get('/api/reservations?page=1&per_page=8')->assertStatus(200);
        $queriesPage8 = $queries;

        // N+1 check: queries count should be constant regardless of number of items on page
        $this->assertGreaterThan(0, $queriesPage2);
        $this->assertEquals($queriesPage2, $queriesPage8);
    }

    public function test_staff_and_therapists_pagination_and_all_flag(): void
    {
        Sanctum::actingAs($this->ownerA);

        // Therapists: default returns active only
        $resActive = $this->get('/api/therapists?page=1&per_page=10');
        $resActive->assertStatus(200);
        $namesActive = collect($resActive->json('data'))->pluck('name');
        $this->assertContains('Therapist Alice', $namesActive);
        $this->assertNotContains('Therapist Amber', $namesActive);

        // Therapists with ?all=1 returns both active and inactive
        $resAll = $this->get('/api/therapists?all=1&page=1&per_page=10');
        $resAll->assertStatus(200);
        $namesAll = collect($resAll->json('data'))->pluck('name');
        $this->assertContains('Therapist Alice', $namesAll);
        $this->assertContains('Therapist Amber', $namesAll);
    }

    public function test_validation_errors_are_422_without_accept_header(): void
    {
        Sanctum::actingAs($this->ownerA);

        // Call without Accept: application/json header
        $res = $this->withHeaders(['Accept' => '*/*'])->get('/api/reservations?per_page=invalid');
        $res->assertStatus(422);
        $res->assertJsonStructure(['message', 'errors']);
    }

    public function test_dashboard_summary_aggregates_and_tenant_isolation(): void
    {
        Sanctum::actingAs($this->ownerA);

        $clientA = Client::create(['spa_id' => $this->spaA->id, 'full_name' => 'Dash Client A', 'phone' => '111']);
        $clientB = Client::create(['spa_id' => $this->spaB->id, 'full_name' => 'Dash Client B', 'phone' => '222']);

        // Today's confirmed reservation for Spa A
        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $clientA->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => now()->format('Y-m-d 10:00:00'),
            'end_time'    => now()->format('Y-m-d 11:00:00'),
            'status'      => 'confirmed',
            'total_price' => 150.00,
        ]);

        // Today's completed reservation for Spa A (counts towards revenue)
        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $clientA->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => now()->format('Y-m-d 12:00:00'),
            'end_time'    => now()->format('Y-m-d 13:00:00'),
            'status'      => 'completed',
            'total_price' => 200.00,
        ]);

        // Upcoming pending reservation for Spa A
        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $clientA->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => now()->addDays(2)->format('Y-m-d 14:00:00'),
            'end_time'    => now()->addDays(2)->format('Y-m-d 15:00:00'),
            'status'      => 'pending',
            'total_price' => 150.00,
        ]);

        // Reservation for Spa B (should NEVER bleed into Spa A's summary)
        Reservation::create([
            'spa_id'      => $this->spaB->id,
            'client_id'   => $clientB->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => now()->format('Y-m-d 10:00:00'),
            'end_time'    => now()->format('Y-m-d 11:00:00'),
            'status'      => 'completed',
            'total_price' => 999.00,
        ]);

        $res = $this->get('/api/dashboard/summary');
        $res->assertStatus(200);

        $json = $res->json();
        $this->assertEquals(2, $json['today_reservations_count']);
        $this->assertEquals(200.00, $json['revenue']);
        $this->assertEquals(1, $json['total_clients']);
        $this->assertEquals(3, $json['total_reservations']);
        $this->assertEquals(1, $json['counts_by_status']['confirmed']);
        $this->assertEquals(1, $json['counts_by_status']['completed']);
        $this->assertEquals(1, $json['counts_by_status']['pending']);
        $this->assertEquals(0, $json['counts_by_status']['cancelled']);
    }
}
