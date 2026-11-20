<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Spa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    private Spa $spaA;
    private User $userA;
    private Spa $spaB;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spaA = Spa::create([
            'name'   => 'Spa Alpha',
            'slug'   => 'spa-alpha',
            'status' => 'active',
        ]);

        $this->userA = User::create([
            'name'     => 'Alice Owner',
            'email'    => 'alice@alpha.test',
            'password' => 'secret123',
            'spa_id'   => $this->spaA->id,
            'role'     => 'owner',
        ]);

        $this->spaB = Spa::create([
            'name'   => 'Spa Beta',
            'slug'   => 'spa-beta',
            'status' => 'active',
        ]);

        $this->userB = User::create([
            'name'     => 'Bob Owner',
            'email'    => 'bob@beta.test',
            'password' => 'secret123',
            'spa_id'   => $this->spaB->id,
            'role'     => 'owner',
        ]);
    }

    // 1. Unauthenticated gets 401
    public function test_unauthenticated_requests_return_401(): void
    {
        $response = $this->getJson('/api/services');
        $response->assertStatus(401)
                 ->assertJson(['message' => 'Unauthenticated.']);

        $responsePost = $this->postJson('/api/services', [
            'name'             => 'Massage',
            'duration_minutes' => 60,
            'price'            => 100,
        ]);
        $responsePost->assertStatus(401);
    }

    // 2. Services CRUD happy paths
    public function test_services_crud_happy_paths(): void
    {
        Sanctum::actingAs($this->userA);

        // Store
        $storeResponse = $this->postJson('/api/services', [
            'name'             => 'Deep Tissue Massage',
            'description'      => 'Full body deep tissue session',
            'duration_minutes' => 60,
            'price'            => 150.50,
            'is_active'        => true,
        ]);

        $storeResponse->assertStatus(201)
            ->assertJson([
                'message' => 'Service created successfully',
                'service' => [
                    'name'             => 'Deep Tissue Massage',
                    'spa_id'           => $this->spaA->id,
                    'duration_minutes' => 60,
                    'is_active'        => true,
                ],
            ]);

        $serviceId = $storeResponse->json('service.id');

        // Show
        $showResponse = $this->getJson("/api/services/{$serviceId}");
        $showResponse->assertStatus(200)
            ->assertJson([
                'id'               => $serviceId,
                'name'             => 'Deep Tissue Massage',
                'spa_id'           => $this->spaA->id,
                'duration_minutes' => 60,
            ]);

        // Update
        $updateResponse = $this->putJson("/api/services/{$serviceId}", [
            'name'  => 'Updated Deep Tissue Massage',
            'price' => 175.00,
        ]);

        $updateResponse->assertStatus(200)
            ->assertJson([
                'message' => 'Service updated successfully',
                'service' => [
                    'id'    => $serviceId,
                    'name'  => 'Updated Deep Tissue Massage',
                    'price' => '175.00',
                ],
            ]);

        // Destroy
        $deleteResponse = $this->deleteJson("/api/services/{$serviceId}");
        $deleteResponse->assertStatus(200)
            ->assertJson(['message' => 'Service deleted successfully']);

        $this->assertDatabaseMissing('services', ['id' => $serviceId]);
    }

    // 3. Validation errors (422)
    public function test_store_validation_errors(): void
    {
        Sanctum::actingAs($this->userA);

        $response = $this->postJson('/api/services', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'duration_minutes', 'price']);

        $invalidResponse = $this->postJson('/api/services', [
            'name'             => 'Invalid Service',
            'duration_minutes' => 3, // min: 5
            'price'            => -10, // min: 0
            'description'      => str_repeat('a', 2001), // max: 2000
        ]);
        $invalidResponse->assertStatus(422)
            ->assertJsonValidationErrors(['duration_minutes', 'price', 'description']);
    }

    // 4. Duplicate name validation including different case
    public function test_duplicate_name_within_same_spa_is_rejected_case_insensitively(): void
    {
        Sanctum::actingAs($this->userA);

        Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Swedish Massage',
            'duration_minutes' => 60,
            'price'            => 120.00,
            'is_active'        => true,
        ]);

        // Exact match
        $dupExact = $this->postJson('/api/services', [
            'name'             => 'Swedish Massage',
            'duration_minutes' => 60,
            'price'            => 120.00,
        ]);
        $dupExact->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // Case-insensitive match
        $dupLower = $this->postJson('/api/services', [
            'name'             => '  swedish massage  ',
            'duration_minutes' => 45,
            'price'            => 90.00,
        ]);
        $dupLower->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // Another service cannot be updated to this name
        $otherService = Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Facial Treatment',
            'duration_minutes' => 45,
            'price'            => 80.00,
            'is_active'        => true,
        ]);

        $updateConflict = $this->putJson("/api/services/{$otherService->id}", [
            'name' => 'SWEDISH MASSAGE',
        ]);
        $updateConflict->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // A service CAN update while keeping its own name
        $updateSelf = $this->putJson("/api/services/{$otherService->id}", [
            'name'  => 'facial treatment',
            'price' => 85.00,
        ]);
        $updateSelf->assertStatus(200);
    }

    // 5. Tenant isolation
    public function test_tenant_isolation_services(): void
    {
        $serviceA = Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Aroma Therapy',
            'duration_minutes' => 60,
            'price'            => 140.00,
            'is_active'        => true,
        ]);

        Sanctum::actingAs($this->userB);

        // Spa B cannot see Spa A's service in index
        $indexResponse = $this->getJson('/api/services');
        $indexResponse->assertStatus(200);
        $this->assertEmpty($indexResponse->json());

        // Spa B gets 404 on show, update, delete for Spa A's service
        $this->getJson("/api/services/{$serviceA->id}")->assertStatus(404);
        $this->putJson("/api/services/{$serviceA->id}", ['name' => 'Hacked'])->assertStatus(404);
        $this->deleteJson("/api/services/{$serviceA->id}")->assertStatus(404);

        // Spa B CAN create a service with the exact same name as Spa A's service
        $createResponse = $this->postJson('/api/services', [
            'name'             => 'Aroma Therapy',
            'duration_minutes' => 60,
            'price'            => 130.00,
        ]);
        $createResponse->assertStatus(201);
    }

    // 6. Delete blocked with 409 when reservations exist
    public function test_delete_is_blocked_with_409_when_reservations_exist(): void
    {
        Sanctum::actingAs($this->userA);

        $service = Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Hydrotherapy',
            'duration_minutes' => 60,
            'price'            => 200.00,
            'is_active'        => true,
        ]);

        $client = Client::create([
            'spa_id'    => $this->spaA->id,
            'full_name' => 'John Doe',
            'phone'     => '+1234567890',
        ]);

        $reservation = Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $client->id,
            'service_id'  => $service->id,
            'start_time'  => now()->addDay(),
            'end_time'    => now()->addDay()->addHour(),
            'status'      => 'confirmed',
            'total_price' => 200.00,
        ]);

        // Attempt delete -> 409
        $deleteResponse = $this->deleteJson("/api/services/{$service->id}");
        $deleteResponse->assertStatus(409)
            ->assertJson([
                'message' => "This service has 1 reservation(s) and can't be deleted. Deactivate it instead.",
            ]);

        $this->assertDatabaseHas('services', ['id' => $service->id]);

        // Remove reservation and retry delete -> 200
        $reservation->delete();
        $this->deleteJson("/api/services/{$service->id}")
            ->assertStatus(200)
            ->assertJson(['message' => 'Service deleted successfully']);

        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    // 7. Index default (active only) vs ?all=1
    public function test_index_filters_active_by_default_and_returns_all_with_query_param(): void
    {
        Sanctum::actingAs($this->userA);

        Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Active Service',
            'duration_minutes' => 30,
            'price'            => 50.00,
            'is_active'        => true,
        ]);

        Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Inactive Service',
            'duration_minutes' => 45,
            'price'            => 70.00,
            'is_active'        => false,
        ]);

        // Default: active only
        $defaultResponse = $this->getJson('/api/services');
        $defaultResponse->assertStatus(200);
        $this->assertCount(1, $defaultResponse->json());
        $this->assertEquals('Active Service', $defaultResponse->json('0.name'));

        // ?all=1: both
        $allResponse = $this->getJson('/api/services?all=1');
        $allResponse->assertStatus(200);
        $this->assertCount(2, $allResponse->json());
    }

    // 8. Reservation store rejects cross-tenant client/service and inactive services
    public function test_reservation_store_tenant_and_inactive_validations(): void
    {
        $clientA = Client::create([
            'spa_id'    => $this->spaA->id,
            'full_name' => 'Alice Client',
            'phone'     => '+1111111111',
        ]);

        $activeServiceA = Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Active Alpha Service',
            'duration_minutes' => 60,
            'price'            => 100.00,
            'is_active'        => true,
        ]);

        $inactiveServiceA = Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Inactive Alpha Service',
            'duration_minutes' => 60,
            'price'            => 100.00,
            'is_active'        => false,
        ]);

        $clientB = Client::create([
            'spa_id'    => $this->spaB->id,
            'full_name' => 'Bob Client',
            'phone'     => '+2222222222',
        ]);

        $serviceB = Service::create([
            'spa_id'           => $this->spaB->id,
            'name'             => 'Beta Service',
            'duration_minutes' => 60,
            'price'            => 100.00,
            'is_active'        => true,
        ]);

        Sanctum::actingAs($this->userA);

        // Reject client from Spa B
        $respCrossClient = $this->postJson('/api/reservations', [
            'client_id'   => $clientB->id,
            'service_id'  => $activeServiceA->id,
            'start_time'  => now()->addDay()->toDateTimeString(),
            'end_time'    => now()->addDay()->addHour()->toDateTimeString(),
            'total_price' => 100.00,
        ]);
        $respCrossClient->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);

        // Reject service from Spa B
        $respCrossService = $this->postJson('/api/reservations', [
            'client_id'   => $clientA->id,
            'service_id'  => $serviceB->id,
            'start_time'  => now()->addDay()->toDateTimeString(),
            'end_time'    => now()->addDay()->addHour()->toDateTimeString(),
            'total_price' => 100.00,
        ]);
        $respCrossService->assertStatus(422)
            ->assertJsonValidationErrors(['service_id']);

        // Reject inactive service
        $respInactive = $this->postJson('/api/reservations', [
            'client_id'   => $clientA->id,
            'service_id'  => $inactiveServiceA->id,
            'start_time'  => now()->addDay()->toDateTimeString(),
            'end_time'    => now()->addDay()->addHour()->toDateTimeString(),
            'total_price' => 100.00,
        ]);
        $respInactive->assertStatus(422)
            ->assertJsonValidationErrors(['service_id'])
            ->assertJson([
                'errors' => [
                    'service_id' => ['This service is not available.'],
                ],
            ]);

        // Happy path succeeds
        $respSuccess = $this->postJson('/api/reservations', [
            'client_id'   => $clientA->id,
            'service_id'  => $activeServiceA->id,
            'start_time'  => now()->addDay()->toDateTimeString(),
            'end_time'    => now()->addDay()->addHour()->toDateTimeString(),
            'total_price' => 100.00,
        ]);
        $respSuccess->assertStatus(201)
            ->assertJson([
                'message' => 'Reservation created successfully',
            ]);
    }

    // 9. Reservation status-only PATCH works even when service is inactive
    public function test_reservation_status_patch_works_when_service_is_inactive(): void
    {
        $client = Client::create([
            'spa_id'    => $this->spaA->id,
            'full_name' => 'Charlie Client',
            'phone'     => '+3333333333',
        ]);

        $service = Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Seasonal Service',
            'duration_minutes' => 60,
            'price'            => 100.00,
            'is_active'        => true,
        ]);

        $reservation = Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $client->id,
            'service_id'  => $service->id,
            'start_time'  => now()->addDay(),
            'end_time'    => now()->addDay()->addHour(),
            'status'      => 'pending',
            'total_price' => 100.00,
        ]);

        // Service becomes inactive
        $service->update(['is_active' => false]);

        Sanctum::actingAs($this->userA);

        // Update status only -> succeeds
        $patchStatus = $this->patchJson("/api/reservations/{$reservation->id}", [
            'status' => 'confirmed',
        ]);
        $patchStatus->assertStatus(200)
            ->assertJson([
                'message'     => 'Reservation updated successfully',
                'reservation' => [
                    'id'     => $reservation->id,
                    'status' => 'confirmed',
                ],
            ]);

        // Update with unchanged service_id -> succeeds
        $patchSameService = $this->patchJson("/api/reservations/{$reservation->id}", [
            'service_id' => $service->id,
            'notes'      => 'Updated notes',
        ]);
        $patchSameService->assertStatus(200);
    }

    // 10. Reservation update with a changed service_id to an inactive one is rejected
    public function test_reservation_update_with_changed_service_to_inactive_is_rejected(): void
    {
        $client = Client::create([
            'spa_id'    => $this->spaA->id,
            'full_name' => 'Dana Client',
            'phone'     => '+4444444444',
        ]);

        $activeService1 = Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Initial Active Service',
            'duration_minutes' => 60,
            'price'            => 100.00,
            'is_active'        => true,
        ]);

        $inactiveService2 = Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Target Inactive Service',
            'duration_minutes' => 60,
            'price'            => 100.00,
            'is_active'        => false,
        ]);

        $reservation = Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $client->id,
            'service_id'  => $activeService1->id,
            'start_time'  => now()->addDay(),
            'end_time'    => now()->addDay()->addHour(),
            'status'      => 'pending',
            'total_price' => 100.00,
        ]);

        Sanctum::actingAs($this->userA);

        // Attempt to switch reservation to the inactive service
        $response = $this->patchJson("/api/reservations/{$reservation->id}", [
            'service_id' => $inactiveService2->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['service_id'])
            ->assertJson([
                'errors' => [
                    'service_id' => ['This service is not available.'],
                ],
            ]);
    }

    // 11. Reservation update with another spa's client_id or service_id returns 422
    public function test_reservation_update_rejects_cross_tenant_client_and_service(): void
    {
        $clientA = Client::create([
            'spa_id'    => $this->spaA->id,
            'full_name' => 'Emma Client',
            'phone'     => '+5555555555',
        ]);

        $serviceA = Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Alpha Service 1',
            'duration_minutes' => 60,
            'price'            => 100.00,
            'is_active'        => true,
        ]);

        $reservation = Reservation::create([
            'spa_id'      => $this->spaA->id,
            'client_id'   => $clientA->id,
            'service_id'  => $serviceA->id,
            'start_time'  => now()->addDay(),
            'end_time'    => now()->addDay()->addHour(),
            'status'      => 'pending',
            'total_price' => 100.00,
        ]);

        $clientB = Client::create([
            'spa_id'    => $this->spaB->id,
            'full_name' => 'Foreign Client',
            'phone'     => '+6666666666',
        ]);

        $serviceB = Service::create([
            'spa_id'           => $this->spaB->id,
            'name'             => 'Foreign Service',
            'duration_minutes' => 60,
            'price'            => 100.00,
            'is_active'        => true,
        ]);

        Sanctum::actingAs($this->userA);

        // Reject updating client_id to another spa's client
        $responseClient = $this->patchJson("/api/reservations/{$reservation->id}", [
            'client_id' => $clientB->id,
        ]);
        $responseClient->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);

        // Reject updating service_id to another spa's service
        $responseService = $this->patchJson("/api/reservations/{$reservation->id}", [
            'service_id' => $serviceB->id,
        ]);
        $responseService->assertStatus(422)
            ->assertJsonValidationErrors(['service_id']);
    }
}
