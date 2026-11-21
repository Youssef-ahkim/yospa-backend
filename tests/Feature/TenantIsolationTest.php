<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Spa;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Spa $spa;
    private User $owner;
    private User $superuser;
    private Client $client;
    private Service $service;
    private Therapist $therapist;
    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spa = Spa::create([
            'name'   => 'Zenith Oasis Spa',
            'slug'   => 'zenith-oasis-spa',
            'status' => 'active',
        ]);

        $this->owner = User::create([
            'name'      => 'Owner User',
            'email'     => 'owner@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->superuser = User::create([
            'name'      => 'Platform Superuser',
            'email'     => 'super@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => null,
            'role'      => 'superuser',
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'spa_id'    => $this->spa->id,
            'full_name' => 'Alice Martin',
            'phone'     => '+212600000099',
        ]);

        $this->service = Service::create([
            'spa_id'           => $this->spa->id,
            'name'             => 'Isolation Service',
            'duration_minutes' => 60,
            'price'            => 150,
            'is_active'        => true,
        ]);

        $this->therapist = Therapist::create([
            'spa_id'    => $this->spa->id,
            'name'      => 'Isolation Therapist',
            'is_active' => true,
        ]);

        $this->reservation = Reservation::create([
            'spa_id'       => $this->spa->id,
            'client_id'    => $this->client->id,
            'service_id'   => $this->service->id,
            'therapist_id' => $this->therapist->id,
            'start_time'   => now()->addDay(),
            'end_time'     => now()->addDay()->addHour(),
            'total_price'  => 150,
        ]);
    }

    public function test_superuser_gets_403_on_all_tenant_routes(): void
    {
        Sanctum::actingAs($this->superuser);

        $tenantEndpoints = [
            ['GET', '/api/clients'],
            ['POST', '/api/clients', ['full_name' => 'Test', 'phone' => '123']],
            ['GET', "/api/clients/{$this->client->id}"],
            ['PATCH', "/api/clients/{$this->client->id}", ['full_name' => 'Updated']],
            ['DELETE', "/api/clients/{$this->client->id}"],

            ['GET', '/api/reservations'],
            ['POST', '/api/reservations', ['client_id' => 1, 'service_id' => 1, 'start_time' => now()]],
            ['GET', "/api/reservations/{$this->reservation->id}"],
            ['PATCH', "/api/reservations/{$this->reservation->id}", ['status' => 'confirmed']],
            ['DELETE', "/api/reservations/{$this->reservation->id}"],

            ['GET', '/api/services'],
            ['POST', '/api/services', ['name' => 'New Service', 'price' => 50]],
            ['GET', "/api/services/{$this->service->id}"],
            ['PATCH', "/api/services/{$this->service->id}", ['name' => 'Updated Service']],
            ['DELETE', "/api/services/{$this->service->id}"],

            ['GET', '/api/therapists'],
            ['POST', '/api/therapists', ['name' => 'New Therapist']],
            ['GET', "/api/therapists/{$this->therapist->id}"],
            ['PATCH', "/api/therapists/{$this->therapist->id}", ['name' => 'Updated Therapist']],
            ['DELETE', "/api/therapists/{$this->therapist->id}"],

            ['GET', '/api/staff'],
            ['POST', '/api/staff', ['name' => 'Staff', 'email' => 's@yospa.test', 'password' => 'secret123']],
            ['PATCH', "/api/staff/{$this->owner->id}", ['name' => 'Staff Updated']],
        ];

        foreach ($tenantEndpoints as $endpoint) {
            $method  = $endpoint[0];
            $uri     = $endpoint[1];
            $payload = $endpoint[2] ?? [];

            $response = match ($method) {
                'GET'    => $this->getJson($uri),
                'POST'   => $this->postJson($uri, $payload),
                'PATCH'  => $this->patchJson($uri, $payload),
                'DELETE' => $this->deleteJson($uri),
            };

            $response->assertStatus(403)
                ->assertJson(['message' => 'Unauthorized action.']);
        }
    }

    public function test_superuser_can_access_non_tenant_routes(): void
    {
        $token = $this->superuser->createToken('super-token')->plainTextToken;

        // GET /api/user returns superuser info with spa: null
        $userRes = $this->withToken($token)->getJson('/api/user');
        $userRes->assertStatus(200)
            ->assertJson([
                'id'     => $this->superuser->id,
                'role'   => 'superuser',
                'spa_id' => null,
                'spa'    => null,
            ]);

        // POST /api/user/password works for superuser
        $passRes = $this->withToken($token)->postJson('/api/user/password', [
            'current_password'      => 'password123',
            'password'              => 'newsuperpassword123',
            'password_confirmation' => 'newsuperpassword123',
        ]);
        $passRes->assertStatus(200);

        // POST /api/logout works for superuser
        $logoutRes = $this->withToken($token)->postJson('/api/logout');
        $logoutRes->assertStatus(200);
    }

    public function test_superuser_login_succeeds(): void
    {
        $response = $this->postJson('/api/login', [
            'email'    => 'super@yospa.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user'])
            ->assertJson([
                'user' => [
                    'role'   => 'superuser',
                    'spa_id' => null,
                ],
            ]);
    }

    public function test_non_superuser_with_null_spa_is_refused_at_login(): void
    {
        User::create([
            'name'      => 'Orphan Staff',
            'email'     => 'orphan@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => null,
            'role'      => 'staff',
            'is_active' => true,
        ]);

        // Wrong password returns 422 (password check precedes null-spa check)
        $wrongPass = $this->postJson('/api/login', [
            'email'    => 'orphan@yospa.test',
            'password' => 'wrongpassword',
        ]);
        $wrongPass->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // Correct password returns 403 deactivated account
        $correctPass = $this->postJson('/api/login', [
            'email'    => 'orphan@yospa.test',
            'password' => 'password123',
        ]);
        $correctPass->assertStatus(403)
            ->assertJson(['message' => 'Your account has been deactivated.']);
    }

    public function test_non_superuser_with_null_spa_mid_session_revokes_token(): void
    {
        $user = User::create([
            'name'      => 'Mid Session User',
            'email'     => 'midsession@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa->id,
            'role'      => 'staff',
            'is_active' => true,
        ]);

        $token = $user->createToken('active-token')->plainTextToken;

        // Valid while user belongs to spa
        $this->withToken($token)->getJson('/api/user')->assertStatus(200);

        // Remove spa_id mid-session
        $user->update(['spa_id' => null]);

        // Next request gets 403 and deletes token
        $response = $this->withToken($token)->getJson('/api/user');
        $response->assertStatus(403)
            ->assertJson(['message' => 'Your account has been deactivated.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_belongs_to_spa_fails_closed_when_authenticated_without_spa(): void
    {
        Sanctum::actingAs($this->superuser);

        // Even though records exist in the DB for Zenith Oasis Spa,
        // BelongsToSpa fail-closed returns 0 records
        $this->assertEquals(0, Client::count());
        $this->assertEquals(0, Service::count());
        $this->assertEquals(0, Therapist::count());
        $this->assertEquals(0, Reservation::count());
    }

    public function test_staff_controller_rejects_superuser_role(): void
    {
        Sanctum::actingAs($this->owner);

        // Store rejects superuser
        $storeRes = $this->postJson('/api/staff', [
            'name'     => 'Hacker',
            'email'    => 'hacker@yospa.test',
            'password' => 'password123',
            'role'     => 'superuser',
        ]);
        $storeRes->assertStatus(422)
            ->assertJsonValidationErrors(['role']);

        // Update rejects superuser
        $staffMember = User::create([
            'name'      => 'Regular Staff',
            'email'     => 'regular@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa->id,
            'role'      => 'staff',
            'is_active' => true,
        ]);

        $updateRes = $this->patchJson("/api/staff/{$staffMember->id}", [
            'role' => 'superuser',
        ]);
        $updateRes->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_cli_commands_continue_to_work(): void
    {
        // yospa:list-spas runs without issue
        $this->artisan('yospa:list-spas')
            ->assertExitCode(0);

        // yospa:create-spa runs without issue with prompt answers
        $newSpaSlug = 'test-cli-spa-' . uniqid();
        $this->artisan('yospa:create-spa', [
            '--name'        => 'CLI Test Spa',
            '--slug'        => $newSpaSlug,
            '--owner-name'  => 'CLI Owner',
            '--owner-email' => 'cli-owner-' . uniqid() . '@yospa.test',
        ])
            ->expectsQuestion('Owner Password (min 8 characters)', 'password123')
            ->expectsQuestion('Confirm Owner Password', 'password123')
            ->assertExitCode(0);
    }
}
