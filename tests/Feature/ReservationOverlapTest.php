<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Spa;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReservationOverlapTest extends TestCase
{
    use RefreshDatabase;

    private Spa $spaA;
    private Spa $spaB;
    private User $ownerA;
    private Client $clientA;
    private Service $serviceA;
    private Therapist $therapistA1;
    private Therapist $therapistA2;
    private Therapist $therapistB;

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
            'name'      => 'Alice Owner',
            'email'     => 'alice@alpha.test',
            'password'  => Hash::make('secret123'),
            'spa_id'    => $this->spaA->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->clientA = Client::create([
            'spa_id'    => $this->spaA->id,
            'full_name' => 'Alice Client',
            'phone'     => '+212600000000',
        ]);

        $this->serviceA = Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Hammam Session',
            'duration_minutes' => 60,
            'price'            => 200.00,
            'is_active'        => true,
        ]);

        $this->therapistA1 = Therapist::create([
            'spa_id'    => $this->spaA->id,
            'name'      => 'Amina Therapist',
            'color'     => '#3B82F6',
            'is_active' => true,
        ]);

        $this->therapistA2 = Therapist::create([
            'spa_id'    => $this->spaA->id,
            'name'      => 'Karim Therapist',
            'color'     => '#EC4899',
            'is_active' => true,
        ]);

        $this->therapistB = Therapist::create([
            'spa_id'    => $this->spaB->id,
            'name'      => 'Foreign Therapist',
            'color'     => '#10B981',
            'is_active' => true,
        ]);
    }

    // 1. Exact overlap rejected with 422
    public function test_exact_overlap_is_rejected(): void
    {
        Sanctum::actingAs($this->ownerA);

        Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'status'       => 'confirmed',
            'total_price'  => 200.00,
        ]);

        // Attempt exact same time slot without Accept header
        $response = $this->post('/api/reservations', [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'total_price'  => 200.00,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'This therapist already has a reservation from 14:00 to 15:00.',
                'errors'  => [
                    'therapist_id' => ['This therapist already has a reservation from 14:00 to 15:00.'],
                ],
            ]);
    }

    // 2. Partial overlap at both ends (start and end) rejected
    public function test_partial_overlap_at_both_ends_is_rejected(): void
    {
        Sanctum::actingAs($this->ownerA);

        Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'status'       => 'confirmed',
            'total_price'  => 200.00,
        ]);

        // (a) Partial overlap at start (13:30 - 14:30)
        $respStart = $this->post('/api/reservations', [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 13:30:00',
            'end_time'     => '2026-11-24 14:30:00',
            'total_price'  => 200.00,
        ]);

        $respStart->assertStatus(422)
            ->assertJson([
                'message' => 'This therapist already has a reservation from 14:00 to 15:00.',
                'errors'  => [
                    'therapist_id' => ['This therapist already has a reservation from 14:00 to 15:00.'],
                ],
            ]);

        // (b) Partial overlap at end (14:30 - 15:30)
        $respEnd = $this->post('/api/reservations', [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:30:00',
            'end_time'     => '2026-11-24 15:30:00',
            'total_price'  => 200.00,
        ]);

        $respEnd->assertStatus(422)
            ->assertJson([
                'message' => 'This therapist already has a reservation from 14:00 to 15:00.',
                'errors'  => [
                    'therapist_id' => ['This therapist already has a reservation from 14:00 to 15:00.'],
                ],
            ]);
    }

    // 3. Containment (new inside existing, existing inside new) rejected
    public function test_containment_is_rejected(): void
    {
        Sanctum::actingAs($this->ownerA);

        Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 16:00:00',
            'status'       => 'confirmed',
            'total_price'  => 200.00,
        ]);

        // (a) New inside existing (14:30 - 15:30 inside 14:00 - 16:00)
        $respInside = $this->post('/api/reservations', [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:30:00',
            'end_time'     => '2026-11-24 15:30:00',
            'total_price'  => 200.00,
        ]);

        $respInside->assertStatus(422)
            ->assertJson([
                'message' => 'This therapist already has a reservation from 14:00 to 16:00.',
            ]);

        // (b) Existing inside new (13:30 - 16:30 wrapping 14:00 - 16:00)
        $respWrapping = $this->post('/api/reservations', [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 13:30:00',
            'end_time'     => '2026-11-24 16:30:00',
            'total_price'  => 200.00,
        ]);

        $respWrapping->assertStatus(422)
            ->assertJson([
                'message' => 'This therapist already has a reservation from 14:00 to 16:00.',
            ]);
    }

    // 4. Back-to-back bookings are allowed (14:00-15:00 then 15:00-16:00, and 13:00-14:00)
    public function test_back_to_back_is_allowed(): void
    {
        Sanctum::actingAs($this->ownerA);

        Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'status'       => 'confirmed',
            'total_price'  => 200.00,
        ]);

        // (a) Immediately after (15:00 - 16:00)
        $respAfter = $this->post('/api/reservations', [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 15:00:00',
            'end_time'     => '2026-11-24 16:00:00',
            'total_price'  => 200.00,
        ]);
        $respAfter->assertStatus(201);

        // (b) Immediately before (13:00 - 14:00)
        $respBefore = $this->post('/api/reservations', [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 13:00:00',
            'end_time'     => '2026-11-24 14:00:00',
            'total_price'  => 200.00,
        ]);
        $respBefore->assertStatus(201);
    }

    // 5. Different therapist allowed at same time
    public function test_different_therapist_allowed(): void
    {
        Sanctum::actingAs($this->ownerA);

        Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'status'       => 'confirmed',
            'total_price'  => 200.00,
        ]);

        // Therapist A2 at exact same time succeeds
        $respDiff = $this->post('/api/reservations', [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA2->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'total_price'  => 200.00,
        ]);
        $respDiff->assertStatus(201);
    }

    // 6. Cancelled reservation is ignored
    public function test_cancelled_reservation_is_ignored(): void
    {
        Sanctum::actingAs($this->ownerA);

        Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'status'       => 'cancelled',
            'total_price'  => 200.00,
        ]);

        $response = $this->post('/api/reservations', [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'total_price'  => 200.00,
        ]);

        $response->assertStatus(201);
    }

    // 7. Update ignores itself
    public function test_update_ignores_itself(): void
    {
        Sanctum::actingAs($this->ownerA);

        $res = Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'status'       => 'confirmed',
            'total_price'  => 200.00,
        ]);

        $response = $this->patch("/api/reservations/{$res->id}", [
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'notes'        => 'Updated notes without time shift',
        ]);

        $response->assertStatus(200);
    }

    // 8. Reactivating a cancelled reservation into a conflict gets 422
    public function test_reactivating_cancelled_reservation_into_conflict_gets_422(): void
    {
        Sanctum::actingAs($this->ownerA);

        // Active reservation in the slot
        Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'status'       => 'confirmed',
            'total_price'  => 200.00,
        ]);

        // Cancelled reservation in same slot
        $cancelled = Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'status'       => 'cancelled',
            'total_price'  => 200.00,
        ]);

        // Reactivating it to confirmed returns 422
        $response = $this->patch("/api/reservations/{$cancelled->id}", [
            'status' => 'confirmed',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'This therapist already has a reservation from 14:00 to 15:00.',
                'errors'  => [
                    'therapist_id' => ['This therapist already has a reservation from 14:00 to 15:00.'],
                ],
            ]);
    }

    // 9. Status change that doesn't affect time or therapist is not blocked
    public function test_status_change_without_time_or_therapist_change_not_blocked(): void
    {
        Sanctum::actingAs($this->ownerA);

        $res = Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'status'       => 'confirmed',
            'total_price'  => 200.00,
        ]);

        $response = $this->patch("/api/reservations/{$res->id}", [
            'status' => 'completed',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'reservation' => [
                    'status' => 'completed',
                ],
            ]);
    }

    // 10. Foreign spa's reservation never conflicts
    public function test_foreign_spa_reservation_never_conflicts(): void
    {
        $clientB = Client::create([
            'spa_id'    => $this->spaB->id,
            'full_name' => 'Foreign Client',
            'phone'     => '+212611111111',
        ]);

        $serviceB = Service::create([
            'spa_id'           => $this->spaB->id,
            'name'             => 'Foreign Service',
            'duration_minutes' => 60,
            'price'            => 100.00,
            'is_active'        => true,
        ]);

        // Foreign spa has reservation at same time
        Reservation::create([
            'spa_id'       => $this->spaB->id,
            'client_id'    => $clientB->id,
            'service_id'   => $serviceB->id,
            'therapist_id' => $this->therapistB->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'status'       => 'confirmed',
            'total_price'  => 200.00,
        ]);

        Sanctum::actingAs($this->ownerA);

        // Own spa booking therapist A1 at same time succeeds
        $response = $this->post('/api/reservations', [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'total_price'  => 200.00,
        ]);

        $response->assertStatus(201);
    }

    // 11. Two sequential conflicting creates (second gets 422)
    public function test_two_sequential_conflicting_creates(): void
    {
        Sanctum::actingAs($this->ownerA);

        $payload = [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'total_price'  => 200.00,
        ];

        // First succeeds
        $first = $this->post('/api/reservations', $payload);
        $first->assertStatus(201);

        // Second fails with 422
        $second = $this->post('/api/reservations', [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:30:00',
            'end_time'     => '2026-11-24 15:30:00',
            'total_price'  => 200.00,
        ]);

        $second->assertStatus(422)
            ->assertJson([
                'message' => 'This therapist already has a reservation from 14:00 to 15:00.',
                'errors'  => [
                    'therapist_id' => ['This therapist already has a reservation from 14:00 to 15:00.'],
                ],
            ]);
    }

    // 12. Date-range span cap (42 days OK, 43 days gets 422) on unpaginated query
    public function test_date_range_span_cap(): void
    {
        Sanctum::actingAs($this->ownerA);

        // (a) 42 days inclusive: 2026-11-01 to 2026-12-12 (Nov=30 days, Dec=12 days: 30+12 = 42)
        $res42 = $this->get('/api/reservations?from=2026-11-01&to=2026-12-12');
        $res42->assertStatus(200);
        $res42->assertHeader('X-Legacy-Unpaginated', '1');
        $this->assertIsArray($res42->json());

        // (b) 43 days inclusive: 2026-11-01 to 2026-12-13 (Nov=30 days, Dec=13 days: 30+13 = 43) -> 422
        $res43 = $this->get('/api/reservations?from=2026-11-01&to=2026-12-13');
        $res43->assertStatus(422)
            ->assertJson([
                'message' => 'The date range may not exceed 42 days.',
                'errors'  => [
                    'date' => ['The date range may not exceed 42 days.'],
                ],
            ]);

        // (c) Paginated query with >42 days is allowed
        $resPaginated = $this->get('/api/reservations?from=2026-11-01&to=2026-12-13&page=1');
        $resPaginated->assertStatus(200);
    }

    // 13. End time before or equal to start time is rejected
    public function test_end_time_before_or_equal_to_start_time_rejected(): void
    {
        Sanctum::actingAs($this->ownerA);

        // Store with end_time == start_time
        $respStore = $this->post('/api/reservations', [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 14:00:00',
            'total_price'  => 200.00,
        ]);
        $respStore->assertStatus(422);

        $res = Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA1->id,
            'start_time'   => '2026-11-24 14:00:00',
            'end_time'     => '2026-11-24 15:00:00',
            'status'       => 'confirmed',
            'total_price'  => 200.00,
        ]);

        // Update with end_time before start_time
        $respUpdate = $this->patch("/api/reservations/{$res->id}", [
            'end_time' => '2026-11-24 13:00:00',
        ]);
        $respUpdate->assertStatus(422)
            ->assertJson([
                'message' => 'The end time field must be a date after start time.',
                'errors'  => [
                    'end_time' => ['The end time field must be a date after start time.'],
                ],
            ]);
    }
}
