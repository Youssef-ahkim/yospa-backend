<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Spa;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardSalesReportTest extends TestCase
{
    use RefreshDatabase;

    private Spa $spaA;
    private Spa $spaB;
    private User $ownerA;
    private User $adminA;
    private User $staffA;
    private User $ownerB;
    private Client $clientA;
    private Client $clientB;
    private Service $serviceA;
    private Service $serviceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spaA = Spa::create([
            'name'   => 'Alpha Wellness Spa',
            'slug'   => 'alpha-wellness-spa',
            'status' => 'active',
        ]);

        $this->spaB = Spa::create([
            'name'   => 'Beta Retreat Spa',
            'slug'   => 'beta-retreat-spa',
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

        $this->clientA = Client::create([
            'spa_id'    => $this->spaA->id,
            'full_name' => 'Alice Client',
            'phone'     => '0611223344',
        ]);

        $this->clientB = Client::create([
            'spa_id'    => $this->spaB->id,
            'full_name' => 'Bob Client',
            'phone'     => '0655667788',
        ]);

        $this->serviceA = Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Aromatherapy Massage',
            'duration_minutes' => 60,
            'price'            => 100.00,
            'is_active'        => true,
        ]);

        $this->serviceB = Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Hot Stone Therapy',
            'duration_minutes' => 90,
            'price'            => 150.00,
            'is_active'        => true,
        ]);
    }

    public function test_invalid_or_missing_range_returns_422(): void
    {
        Sanctum::actingAs($this->ownerA);

        // Missing range
        $this->get('/api/dashboard/sales-report')->assertStatus(422);
        $this->get('/api/dashboard/best-sellers')->assertStatus(422);

        // Invalid range parameter
        $this->get('/api/dashboard/sales-report?range=invalid')->assertStatus(422);
        $this->get('/api/dashboard/sales-report?range=90d')->assertStatus(422);
        $this->get('/api/dashboard/best-sellers?range=unknown')->assertStatus(422);
    }

    public function test_sales_report_bucket_boundaries_and_zero_filling_across_all_ranges(): void
    {
        Sanctum::actingAs($this->ownerA);
        Carbon::setTestNow(Carbon::parse('2026-11-24 14:00:00', 'UTC'));

        // 1. 1d: exactly 24 hourly buckets, all zero-filled
        $res1d = $this->get('/api/dashboard/sales-report?range=1d');
        $res1d->assertStatus(200);
        $json1d = $res1d->json();
        $this->assertEquals('1d', $json1d['range']);
        $this->assertCount(24, $json1d['buckets']);
        $this->assertEquals('00:00', $json1d['buckets'][0]['label']);
        $this->assertEquals('23:00', $json1d['buckets'][23]['label']);
        $this->assertEquals(0, $json1d['totals']['completed_count']);
        $this->assertEquals(0, $json1d['totals']['bookings']);
        $this->assertEquals(0.0, $json1d['totals']['revenue']);

        // 2. 7d: exactly 7 daily buckets, all zero-filled
        $res7d = $this->get('/api/dashboard/sales-report?range=7d');
        $res7d->assertStatus(200);
        $json7d = $res7d->json();
        $this->assertEquals('7d', $json7d['range']);
        $this->assertCount(7, $json7d['buckets']);
        $this->assertEquals(0, $json7d['totals']['completed_count']);
        $this->assertEquals(0, $json7d['totals']['bookings']);
        $this->assertEquals(0.0, $json7d['totals']['revenue']);

        // 3. 30d: exactly 30 daily buckets, all zero-filled
        $res30d = $this->get('/api/dashboard/sales-report?range=30d');
        $res30d->assertStatus(200);
        $json30d = $res30d->json();
        $this->assertEquals('30d', $json30d['range']);
        $this->assertCount(30, $json30d['buckets']);

        // 4. 12m: exactly 12 monthly buckets, all zero-filled
        $res12m = $this->get('/api/dashboard/sales-report?range=12m');
        $res12m->assertStatus(200);
        $json12m = $res12m->json();
        $this->assertEquals('12m', $json12m['range']);
        $this->assertCount(12, $json12m['buckets']);
    }

    public function test_sales_report_spa_timezone_boundary_and_midnight_local_hour(): void
    {
        Sanctum::actingAs($this->ownerA);

        // At 2026-11-23 23:30:00 UTC, the local time in Africa/Casablanca (UTC+1) is 2026-11-24 00:30:00:
        Carbon::setTestNow(Carbon::parse('2026-11-23 23:30:00', 'UTC'));

        // Create completed reservation at 00:30 on Nov 24 local time
        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $this->clientA->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => '2026-11-24 00:30:00',
            'end_time'    => '2026-11-24 01:30:00',
            'status'      => 'completed',
            'total_price' => 120.00,
        ]);

        // Range 1d on Nov 24: bucket 0 (00:00) must hold this reservation
        $res1d = $this->get('/api/dashboard/sales-report?range=1d');
        $res1d->assertStatus(200);
        $json1d = $res1d->json();

        $bucket0 = $json1d['buckets'][0];
        $this->assertEquals('00:00', $bucket0['label']);
        $this->assertEquals('2026-11-24 00:00:00', $bucket0['start']);
        $this->assertEquals(1, $bucket0['completed_count']);
        $this->assertEquals(1, $bucket0['bookings']);
        $this->assertEquals(120.00, $bucket0['revenue']);

        // Subsequent hour buckets must remain 0
        $this->assertEquals(0, $json1d['buckets'][1]['bookings']);
        $this->assertEquals(120.00, $json1d['totals']['revenue']);

        // Range 7d: Nov 24 must hold this reservation
        $res7d = $this->get('/api/dashboard/sales-report?range=7d');
        $res7d->assertStatus(200);
        $json7d = $res7d->json();
        $todayBucket = end($json7d['buckets']);
        $this->assertEquals('2026-11-24 00:00:00', $todayBucket['start']);
        $this->assertEquals(1, $todayBucket['completed_count']);
        $this->assertEquals(120.00, $todayBucket['revenue']);
    }

    public function test_sales_report_cancelled_excluded_and_completed_only_revenue(): void
    {
        Sanctum::actingAs($this->ownerA);
        Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'UTC'));

        // 1. Completed reservation: counts in revenue, completed_count, and bookings
        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $this->clientA->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => '2026-11-24 10:00:00',
            'end_time'    => '2026-11-24 11:00:00',
            'status'      => 'completed',
            'total_price' => 100.00,
        ]);

        // 2. Confirmed reservation: counts in bookings, but NOT in revenue or completed_count
        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $this->clientA->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => '2026-11-24 10:30:00',
            'end_time'    => '2026-11-24 11:30:00',
            'status'      => 'confirmed',
            'total_price' => 200.00,
        ]);

        // 3. Pending reservation: counts in bookings, but NOT in revenue or completed_count
        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $this->clientA->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => '2026-11-24 15:00:00',
            'end_time'    => '2026-11-24 16:00:00',
            'status'      => 'pending',
            'total_price' => 300.00,
        ]);

        // 4. Cancelled reservation: completely excluded from bookings, completed_count, and revenue
        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $this->clientA->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => '2026-11-24 10:45:00',
            'end_time'    => '2026-11-24 11:45:00',
            'status'      => 'cancelled',
            'total_price' => 500.00,
        ]);

        $res = $this->get('/api/dashboard/sales-report?range=1d');
        $res->assertStatus(200);
        $json = $res->json();

        // 1 completed (100), 1 confirmed (200), 1 pending (300) = 3 bookings
        $this->assertEquals(3, $json['totals']['bookings']);
        $this->assertEquals(1, $json['totals']['completed_count']);
        $this->assertEquals(100.00, $json['totals']['revenue']);

        // Check hour 10 bucket: holds completed (100) and confirmed (200), excludes cancelled (500)
        $hour10 = $json['buckets'][10];
        $this->assertEquals(2, $hour10['bookings']);
        $this->assertEquals(1, $hour10['completed_count']);
        $this->assertEquals(100.00, $hour10['revenue']);
    }

    public function test_staff_role_gets_revenue_null_in_reports_and_best_sellers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'UTC'));

        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $this->clientA->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => '2026-11-24 10:00:00',
            'end_time'    => '2026-11-24 11:00:00',
            'status'      => 'completed',
            'total_price' => 250.00,
        ]);

        // As staff
        Sanctum::actingAs($this->staffA);

        // 1. Sales report: counts visible, revenue null
        $resReport = $this->get('/api/dashboard/sales-report?range=7d');
        $resReport->assertStatus(200);
        $jsonReport = $resReport->json();

        $this->assertNull($jsonReport['totals']['revenue']);
        $this->assertEquals(1, $jsonReport['totals']['completed_count']);
        $this->assertEquals(1, $jsonReport['totals']['bookings']);

        foreach ($jsonReport['buckets'] as $bucket) {
            $this->assertNull($bucket['revenue']);
        }

        // 2. Best sellers: count visible, revenue null
        $resBest = $this->get('/api/dashboard/best-sellers?range=7d');
        $resBest->assertStatus(200);
        $jsonBest = $resBest->json();

        $this->assertCount(1, $jsonBest);
        $this->assertEquals(1, $jsonBest[0]['count']);
        $this->assertNull($jsonBest[0]['revenue']);
        $this->assertEquals($this->serviceA->id, $jsonBest[0]['service_id']);

        // As admin: revenue is numeric
        Sanctum::actingAs($this->adminA);
        $resAdmin = $this->get('/api/dashboard/sales-report?range=7d');
        $this->assertEquals(250.00, $resAdmin->json('totals.revenue'));

        $resAdminBest = $this->get('/api/dashboard/best-sellers?range=7d');
        $this->assertEquals(250.00, $resAdminBest->json('0.revenue'));
    }

    public function test_tenant_isolation_never_leaks_another_spas_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'UTC'));

        // Spa B reservation
        $serviceBeta = Service::create([
            'spa_id'           => $this->spaB->id,
            'name'             => 'Beta Secret Treatment',
            'duration_minutes' => 60,
            'price'            => 999.00,
            'is_active'        => true,
        ]);

        Reservation::create([
            'spa_id'      => $this->spaB->id,
            'client_id'   => $this->clientB->id,
            'service_id'  => $serviceBeta->id,
            'start_time'  => '2026-11-24 10:00:00',
            'end_time'    => '2026-11-24 11:00:00',
            'status'      => 'completed',
            'total_price' => 999.00,
        ]);

        // Spa A caller
        Sanctum::actingAs($this->ownerA);

        $resReport = $this->get('/api/dashboard/sales-report?range=1d');
        $resReport->assertStatus(200);
        $this->assertEquals(0, $resReport->json('totals.bookings'));
        $this->assertEquals(0, $resReport->json('totals.completed_count'));
        $this->assertEquals(0.0, $resReport->json('totals.revenue'));

        $resBest = $this->get('/api/dashboard/best-sellers?range=1d');
        $resBest->assertStatus(200);
        $this->assertEmpty($resBest->json());
        $this->assertStringNotContainsString('Beta Secret Treatment', $resBest->getContent());
    }

    public function test_best_sellers_ordering_and_tie_breaking(): void
    {
        Sanctum::actingAs($this->ownerA);
        Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'UTC'));

        // Create 6 services
        $s1 = Service::create(['spa_id' => $this->spaA->id, 'name' => 'Service 1 Top', 'price' => 100]);
        $s2 = Service::create(['spa_id' => $this->spaA->id, 'name' => 'Beta Service', 'price' => 100]);
        $s3 = Service::create(['spa_id' => $this->spaA->id, 'name' => 'Alpha Service', 'price' => 100]);
        $s4 = Service::create(['spa_id' => $this->spaA->id, 'name' => 'Service 4 Mid', 'price' => 100]);
        $s5 = Service::create(['spa_id' => $this->spaA->id, 'name' => 'Service 5 Low', 'price' => 100]);
        $s6 = Service::create(['spa_id' => $this->spaA->id, 'name' => 'Service 6 Excluded', 'price' => 100]);

        // S1: 5 completed
        for ($i = 0; $i < 5; $i++) {
            Reservation::create([
                'spa_id'      => $this->spaA->id,
                'client_id'   => $this->clientA->id,
                'service_id'  => $s1->id,
                'start_time'  => '2026-11-24 10:00:00',
                'end_time'    => '2026-11-24 11:00:00',
                'status'      => 'completed',
                'total_price' => 100.00,
            ]);
        }

        // S2 and S3: both 3 completed (tie on count). S3 ('Service 3 Alpha Tie') should beat S2 ('Service 2 Beta Tie') by name!
        for ($i = 0; $i < 3; $i++) {
            Reservation::create([
                'spa_id'      => $this->spaA->id,
                'client_id'   => $this->clientA->id,
                'service_id'  => $s2->id,
                'start_time'  => '2026-11-24 10:00:00',
                'end_time'    => '2026-11-24 11:00:00',
                'status'      => 'completed',
                'total_price' => 100.00,
            ]);
            Reservation::create([
                'spa_id'      => $this->spaA->id,
                'client_id'   => $this->clientA->id,
                'service_id'  => $s3->id,
                'start_time'  => '2026-11-24 10:00:00',
                'end_time'    => '2026-11-24 11:00:00',
                'status'      => 'completed',
                'total_price' => 100.00,
            ]);
        }

        // S4: 2 completed
        for ($i = 0; $i < 2; $i++) {
            Reservation::create([
                'spa_id'      => $this->spaA->id,
                'client_id'   => $this->clientA->id,
                'service_id'  => $s4->id,
                'start_time'  => '2026-11-24 10:00:00',
                'end_time'    => '2026-11-24 11:00:00',
                'status'      => 'completed',
                'total_price' => 100.00,
            ]);
        }

        // S5: 1 completed
        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $this->clientA->id,
            'service_id'  => $s5->id,
            'start_time'  => '2026-11-24 10:00:00',
            'end_time'    => '2026-11-24 11:00:00',
            'status'      => 'completed',
            'total_price' => 100.00,
        ]);

        // S6: 1 completed, but should be cut off by limit 5
        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $this->clientA->id,
            'service_id'  => $s6->id,
            'start_time'  => '2026-11-24 10:00:00',
            'end_time'    => '2026-11-24 11:00:00',
            'status'      => 'completed',
            'total_price' => 50.00,
        ]);

        // Also add non-completed reservations for S6 - must NOT count
        Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $this->clientA->id,
            'service_id'  => $s6->id,
            'start_time'  => '2026-11-24 10:00:00',
            'end_time'    => '2026-11-24 11:00:00',
            'status'      => 'confirmed',
            'total_price' => 500.00,
        ]);

        $res = $this->get('/api/dashboard/best-sellers?range=1d');
        $res->assertStatus(200);
        $best = $res->json();

        // Must return exactly 5 items
        $this->assertCount(5, $best);

        // 1st: S1 with count 5
        $this->assertEquals($s1->id, $best[0]['service_id']);
        $this->assertEquals(5, $best[0]['count']);
        $this->assertEquals(500.00, $best[0]['revenue']);

        // 2nd: S3 with count 3 ('Service 3 Alpha Tie' precedes 'Service 2 Beta Tie')
        $this->assertEquals($s3->id, $best[1]['service_id']);
        $this->assertEquals(3, $best[1]['count']);

        // 3rd: S2 with count 3
        $this->assertEquals($s2->id, $best[2]['service_id']);
        $this->assertEquals(3, $best[2]['count']);

        // 4th: S4 with count 2
        $this->assertEquals($s4->id, $best[3]['service_id']);
        $this->assertEquals(2, $best[3]['count']);

        // 5th: S5 with count 1
        $this->assertEquals($s5->id, $best[4]['service_id']);
        $this->assertEquals(1, $best[4]['count']);
    }

    public function test_constant_query_count_regardless_of_reservation_volume(): void
    {
        Sanctum::actingAs($this->ownerA);
        Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'UTC'));

        // 1. Measure queries with few reservations
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->get('/api/dashboard/sales-report?range=7d')->assertStatus(200);
        $queriesReportFew = $queries;

        $queries = 0;
        $this->get('/api/dashboard/best-sellers?range=7d')->assertStatus(200);
        $queriesBestFew = $queries;

        // 2. Add 40 reservations
        for ($i = 1; $i <= 40; $i++) {
            Reservation::create([
                'spa_id'      => $this->spaA->id,
                'client_id'   => $this->clientA->id,
                'service_id'  => $this->serviceA->id,
                'start_time'  => '2026-11-24 10:00:00',
                'end_time'    => '2026-11-24 11:00:00',
                'status'      => 'completed',
                'total_price' => 50.00,
            ]);
        }

        // 3. Measure queries with many reservations
        $queries = 0;
        $this->get('/api/dashboard/sales-report?range=7d')->assertStatus(200);
        $queriesReportMany = $queries;

        $queries = 0;
        $this->get('/api/dashboard/best-sellers?range=7d')->assertStatus(200);
        $queriesBestMany = $queries;

        $this->assertEquals($queriesReportFew, $queriesReportMany, 'Sales report query count must stay constant');
        $this->assertEquals($queriesBestFew, $queriesBestMany, 'Best sellers query count must stay constant');
    }
}
