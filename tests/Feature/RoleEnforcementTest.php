<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Spa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Spa $spa;
    private User $owner;
    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spa = Spa::create([
            'name'   => 'Zenith Oasis Spa',
            'slug'   => 'zenith-oasis-spa',
            'status' => 'active',
        ]);

        $this->owner = User::create([
            'name'      => 'Youssef Admin',
            'email'     => 'admin@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'name'      => 'Admin User',
            'email'     => 'admin2@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name'      => 'Staff User',
            'email'     => 'staff@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa->id,
            'role'      => 'staff',
            'is_active' => true,
        ]);
    }

    // 1. Owner has full access to all operations
    public function test_owner_has_full_access(): void
    {
        Sanctum::actingAs($this->owner);

        // Can create, update, delete services
        $serviceRes = $this->postJson('/api/services', [
            'name'             => 'Owner Service',
            'duration_minutes' => 60,
            'price'            => 100,
        ]);
        $serviceRes->assertStatus(201);
        $serviceId = $serviceRes->json('service.id');

        $this->putJson("/api/services/{$serviceId}", ['price' => 120])->assertStatus(200);
        $this->deleteJson("/api/services/{$serviceId}")->assertStatus(200);

        // Can delete client
        $client = Client::create([
            'spa_id'    => $this->spa->id,
            'full_name' => 'Test Client',
            'phone'     => '+212600000000',
        ]);
        $this->deleteJson("/api/clients/{$client->id}")->assertStatus(200);

        // Can delete reservation
        $client2 = Client::create([
            'spa_id'    => $this->spa->id,
            'full_name' => 'Test Client 2',
            'phone'     => '+212600000001',
        ]);
        $service2 = Service::create([
            'spa_id'           => $this->spa->id,
            'name'             => 'Owner Service 2',
            'duration_minutes' => 60,
            'price'            => 100,
            'is_active'        => true,
        ]);
        $reservation = Reservation::create([
            'spa_id'      => $this->spa->id,
            'client_id'   => $client2->id,
            'service_id'  => $service2->id,
            'start_time'  => now()->addDay(),
            'end_time'    => now()->addDay()->addHour(),
            'total_price' => 100,
        ]);
        $this->deleteJson("/api/reservations/{$reservation->id}")->assertStatus(200);
    }

    // 2. Admin can write services and delete clients/reservations
    public function test_admin_has_permissions_to_services_and_deletes(): void
    {
        Sanctum::actingAs($this->admin);

        // Admin can manage services
        $serviceRes = $this->postJson('/api/services', [
            'name'             => 'Admin Service',
            'duration_minutes' => 60,
            'price'            => 100,
        ]);
        $serviceRes->assertStatus(201);
        $serviceId = $serviceRes->json('service.id');

        $this->putJson("/api/services/{$serviceId}", ['price' => 110])->assertStatus(200);
        $this->deleteJson("/api/services/{$serviceId}")->assertStatus(200);

        // Admin can delete client
        $client = Client::create([
            'spa_id'    => $this->spa->id,
            'full_name' => 'Admin Client',
            'phone'     => '+212600000002',
        ]);
        $this->deleteJson("/api/clients/{$client->id}")->assertStatus(200);
    }

    // 3. Staff permissions: read & write clients/reservations OK; delete & service writes forbidden with 403
    public function test_staff_restricted_permissions(): void
    {
        $service = Service::create([
            'spa_id'           => $this->spa->id,
            'name'             => 'Staff Service Test',
            'duration_minutes' => 60,
            'price'            => 100,
            'is_active'        => true,
        ]);

        $client = Client::create([
            'spa_id'    => $this->spa->id,
            'full_name' => 'Staff Client Test',
            'phone'     => '+212600000003',
        ]);

        $reservation = Reservation::create([
            'spa_id'      => $this->spa->id,
            'client_id'   => $client->id,
            'service_id'  => $service->id,
            'start_time'  => now()->addDay(),
            'end_time'    => now()->addDay()->addHour(),
            'total_price' => 100,
        ]);

        Sanctum::actingAs($this->staff);

        // Staff can read
        $this->getJson('/api/services')->assertStatus(200);
        $this->getJson('/api/clients')->assertStatus(200);
        $this->getJson('/api/reservations')->assertStatus(200);

        // Staff can create/update clients
        $clientCreate = $this->postJson('/api/clients', [
            'full_name' => 'Created By Staff',
            'phone'     => '+212600000004',
        ]);
        $clientCreate->assertStatus(201);
        $this->putJson("/api/clients/{$client->id}", ['full_name' => 'Updated By Staff'])->assertStatus(200);

        // Staff can create/update reservations
        $this->patchJson("/api/reservations/{$reservation->id}", ['status' => 'confirmed'])->assertStatus(200);

        // Staff CANNOT delete client -> 403
        $this->deleteJson("/api/clients/{$client->id}")
            ->assertStatus(403)
            ->assertJson(['message' => 'Unauthorized action.']);

        // Staff CANNOT delete reservation -> 403
        $this->deleteJson("/api/reservations/{$reservation->id}")
            ->assertStatus(403)
            ->assertJson(['message' => 'Unauthorized action.']);

        // Staff CANNOT write services -> 403
        $this->postJson('/api/services', [
            'name'             => 'Staff Service Attempt',
            'duration_minutes' => 60,
            'price'            => 100,
        ])->assertStatus(403)->assertJson(['message' => 'Unauthorized action.']);

        $this->putJson("/api/services/{$service->id}", ['price' => 150])
            ->assertStatus(403)->assertJson(['message' => 'Unauthorized action.']);

        $this->deleteJson("/api/services/{$service->id}")
            ->assertStatus(403)->assertJson(['message' => 'Unauthorized action.']);
    }

    // 4. Inactive user cannot log in
    public function test_inactive_user_cannot_login(): void
    {
        $inactiveUser = User::create([
            'name'      => 'Inactive Staff',
            'email'     => 'inactive@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa->id,
            'role'      => 'staff',
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => 'inactive@yospa.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Your account has been deactivated.']);
    }

    // 5. Existing token for deactivated user is rejected and revoked on authenticated requests
    public function test_existing_token_for_deactivated_user_is_rejected_and_revoked(): void
    {
        $user = User::create([
            'name'      => 'Temp User',
            'email'     => 'temp@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa->id,
            'role'      => 'staff',
            'is_active' => true,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        // Active request works
        $this->withToken($token)->getJson('/api/user')->assertStatus(200);

        // Deactivate user
        $user->update(['is_active' => false]);

        // Subsequent authenticated request returns 403 and revokes token
        $response = $this->withToken($token)->getJson('/api/user');
        $response->assertStatus(403)
            ->assertJson(['message' => 'Your account has been deactivated.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // 6. Login checks password before is_active check
    public function test_login_checks_password_before_is_active_check(): void
    {
        User::create([
            'name'      => 'Inactive Check User',
            'email'     => 'inactive-check@yospa.test',
            'password'  => Hash::make('correctpassword'),
            'spa_id'    => $this->spa->id,
            'role'      => 'staff',
            'is_active' => false,
        ]);

        // Wrong password returns 422 standard credential error
        $wrongPasswordResponse = $this->postJson('/api/login', [
            'email'    => 'inactive-check@yospa.test',
            'password' => 'wrongpassword',
        ]);
        $wrongPasswordResponse->assertStatus(422)
            ->assertJsonValidationErrors(['email'])
            ->assertJson([
                'errors' => [
                    'email' => ['The provided credentials are incorrect.'],
                ],
            ]);

        // Correct password returns 403 deactivated account error
        $correctPasswordResponse = $this->postJson('/api/login', [
            'email'    => 'inactive-check@yospa.test',
            'password' => 'correctpassword',
        ]);
        $correctPasswordResponse->assertStatus(403)
            ->assertJson(['message' => 'Your account has been deactivated.']);
    }
}
