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

class ReservationAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Spa $spaA;
    private User $ownerA;
    private Client $clientA;
    private Service $serviceA;
    private Therapist $therapistA;
    private Spa $spaB;
    private Therapist $therapistB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spaA = Spa::create([
            'name'   => 'Spa Alpha',
            'slug'   => 'spa-alpha',
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

        $this->therapistA = Therapist::create([
            'spa_id'    => $this->spaA->id,
            'name'      => 'Amina Therapist',
            'color'     => '#3B82F6',
            'is_active' => true,
        ]);

        $this->spaB = Spa::create([
            'name'   => 'Spa Beta',
            'slug'   => 'spa-beta',
            'status' => 'active',
        ]);

        $this->therapistB = Therapist::create([
            'spa_id'    => $this->spaB->id,
            'name'      => 'Foreign Therapist',
            'color'     => '#10B981',
            'is_active' => true,
        ]);
    }

    // 1. Assign active own-spa therapist OK and eager-loads therapist (id, name, color)
    public function test_assign_active_own_spa_therapist_and_eager_load(): void
    {
        Sanctum::actingAs($this->ownerA);

        $response = $this->postJson('/api/reservations', [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA->id,
            'start_time'   => now()->addDay()->toDateTimeString(),
            'end_time'     => now()->addDay()->addHour()->toDateTimeString(),
            'total_price'  => 200.00,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'message'     => 'Reservation created successfully',
                'reservation' => [
                    'therapist_id' => $this->therapistA->id,
                    'therapist'    => [
                        'id'    => $this->therapistA->id,
                        'name'  => 'Amina Therapist',
                        'color' => '#3B82F6',
                    ],
                ],
            ]);

        $reservationId = $response->json('reservation.id');

        // Show also eager loads therapist
        $show = $this->getJson("/api/reservations/{$reservationId}");
        $show->assertStatus(200)
            ->assertJson([
                'id'        => $reservationId,
                'therapist' => [
                    'id'    => $this->therapistA->id,
                    'name'  => 'Amina Therapist',
                    'color' => '#3B82F6',
                ],
            ]);

        // Index also eager loads therapist
        $index = $this->getJson('/api/reservations');
        $index->assertStatus(200);
        $this->assertEquals('Amina Therapist', $index->json('0.therapist.name'));
    }

    // 2. Other-spa therapist rejected with 422
    public function test_other_spa_therapist_rejected_on_store_and_update(): void
    {
        Sanctum::actingAs($this->ownerA);

        // Store rejects cross-spa therapist
        $responseStore = $this->postJson('/api/reservations', [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistB->id,
            'start_time'   => now()->addDay()->toDateTimeString(),
            'end_time'     => now()->addDay()->addHour()->toDateTimeString(),
            'total_price'  => 200.00,
        ]);
        $responseStore->assertStatus(422)->assertJsonValidationErrors(['therapist_id']);

        // Create valid reservation
        $res = Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $this->clientA->id,
            'service_id'  => $this->serviceA->id,
            'start_time'  => now()->addDay(),
            'end_time'    => now()->addDay()->addHour(),
            'total_price' => 200.00,
        ]);

        // Update rejects cross-spa therapist
        $responseUpdate = $this->patchJson("/api/reservations/{$res->id}", [
            'therapist_id' => $this->therapistB->id,
        ]);
        $responseUpdate->assertStatus(422)->assertJsonValidationErrors(['therapist_id']);
    }

    // 3. Inactive therapist rejected on store and on update change
    public function test_inactive_therapist_rejected_on_store_and_on_change(): void
    {
        $inactiveTherapist = Therapist::create([
            'spa_id'    => $this->spaA->id,
            'name'      => 'Inactive Leila',
            'color'     => '#EF4444',
            'is_active' => false,
        ]);

        Sanctum::actingAs($this->ownerA);

        // Store with inactive therapist -> 422
        $storeResp = $this->postJson('/api/reservations', [
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $inactiveTherapist->id,
            'start_time'   => now()->addDay()->toDateTimeString(),
            'end_time'     => now()->addDay()->addHour()->toDateTimeString(),
            'total_price'  => 200.00,
        ]);
        $storeResp->assertStatus(422)
            ->assertJsonValidationErrors(['therapist_id'])
            ->assertJson([
                'errors' => [
                    'therapist_id' => ['This therapist is not available.'],
                ],
            ]);

        // Create reservation with active therapist
        $res = Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA->id,
            'start_time'   => now()->addDay(),
            'end_time'     => now()->addDay()->addHour(),
            'total_price'  => 200.00,
        ]);

        // Changing therapist to inactive -> 422
        $updateResp = $this->patchJson("/api/reservations/{$res->id}", [
            'therapist_id' => $inactiveTherapist->id,
        ]);
        $updateResp->assertStatus(422)
            ->assertJsonValidationErrors(['therapist_id'])
            ->assertJson([
                'errors' => [
                    'therapist_id' => ['This therapist is not available.'],
                ],
            ]);
    }

    // 4. Keeping an inactive current therapist on update OK
    public function test_keeping_inactive_current_therapist_on_update_is_allowed(): void
    {
        $res = Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA->id,
            'start_time'   => now()->addDay(),
            'end_time'     => now()->addDay()->addHour(),
            'total_price'  => 200.00,
        ]);

        // Therapist becomes inactive later
        $this->therapistA->update(['is_active' => false]);

        Sanctum::actingAs($this->ownerA);

        // Keeping same therapist_id explicitly -> 200
        $updateSame = $this->patchJson("/api/reservations/{$res->id}", [
            'therapist_id' => $this->therapistA->id,
            'notes'        => 'Updated reservation notes',
        ]);
        $updateSame->assertStatus(200);

        // Status-only patch without passing therapist_id -> 200
        $updateStatus = $this->patchJson("/api/reservations/{$res->id}", [
            'status' => 'confirmed',
        ]);
        $updateStatus->assertStatus(200);
    }

    // 5. null unassigns therapist
    public function test_null_unassigns_therapist(): void
    {
        $res = Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $this->clientA->id,
            'service_id'   => $this->serviceA->id,
            'therapist_id' => $this->therapistA->id,
            'start_time'   => now()->addDay(),
            'end_time'     => now()->addDay()->addHour(),
            'total_price'  => 200.00,
        ]);

        Sanctum::actingAs($this->ownerA);

        $response = $this->patchJson("/api/reservations/{$res->id}", [
            'therapist_id' => null,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message'     => 'Reservation updated successfully',
                'reservation' => [
                    'id'           => $res->id,
                    'therapist_id' => null,
                    'therapist'    => null,
                ],
            ]);

        $this->assertNull($res->fresh()->therapist_id);
    }
}
