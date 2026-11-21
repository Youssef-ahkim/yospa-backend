<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Spa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SpaOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private Spa $activeSpa;
    private Spa $suspendedSpa;
    private User $activeOwner;
    private User $suspendedOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activeSpa = Spa::create([
            'name'   => 'Active Spa',
            'slug'   => 'active-spa',
            'status' => 'active',
        ]);

        $this->suspendedSpa = Spa::create([
            'name'   => 'Suspended Spa',
            'slug'   => 'suspended-spa',
            'status' => 'suspended',
        ]);

        $this->activeOwner = User::create([
            'spa_id'    => $this->activeSpa->id,
            'name'      => 'Active Owner',
            'email'     => 'active-owner@yospa.test',
            'password'  => bcrypt('password123'),
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->suspendedOwner = User::create([
            'spa_id'    => $this->suspendedSpa->id,
            'name'      => 'Suspended Owner',
            'email'     => 'suspended-owner@yospa.test',
            'password'  => bcrypt('password123'),
            'role'      => 'owner',
            'is_active' => true,
        ]);
    }

    public function test_suspended_spa_login_and_token_enforcement(): void
    {
        // 1. Wrong password on suspended spa returns 422 invalid credentials (not 403, preventing probing)
        $wrongPassResponse = $this->postJson('/api/login', [
            'email'    => 'suspended-owner@yospa.test',
            'password' => 'wrong-pass',
        ]);
        $wrongPassResponse->assertStatus(422);
        $wrongPassResponse->assertJsonValidationErrors(['email']);

        // 2. Correct password on suspended spa returns 403 "Your account has been deactivated."
        $correctPassResponse = $this->postJson('/api/login', [
            'email'    => 'suspended-owner@yospa.test',
            'password' => 'password123',
        ]);
        $correctPassResponse->assertStatus(403);
        $correctPassResponse->assertJson([
            'message' => 'Your account has been deactivated.',
        ]);

        // 3. Active spa owner can log in normally
        $activeLoginResponse = $this->postJson('/api/login', [
            'email'    => 'active-owner@yospa.test',
            'password' => 'password123',
        ]);
        $activeLoginResponse->assertStatus(200);

        // 4. Existing token for suspended spa is rejected and deleted by EnsureUserIsActive middleware
        $token = $this->suspendedOwner->createToken('test-token')->plainTextToken;
        $reqResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user');
        $reqResponse->assertStatus(403);
        $reqResponse->assertJson([
            'message' => 'Your account has been deactivated.',
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->suspendedOwner->id,
        ]);

        // 5. Reactivating the spa restores access
        $this->suspendedSpa->status = 'active';
        $this->suspendedSpa->save();

        $restoredLogin = $this->postJson('/api/login', [
            'email'    => 'suspended-owner@yospa.test',
            'password' => 'password123',
        ]);
        $restoredLogin->assertStatus(200);
    }

    public function test_create_spa_command_happy_path(): void
    {
        $this->artisan('yospa:create-spa', [
            '--name'        => 'Command Created Spa',
            '--owner-name'  => 'Command Owner',
            '--owner-email' => 'command-owner@yospa.test',
        ])
            ->expectsQuestion('Owner Password (min 8 characters)', 'secretpass123')
            ->expectsQuestion('Confirm Owner Password', 'secretpass123')
            ->assertExitCode(0);

        $spa = Spa::where('slug', 'command-created-spa')->first();
        $this->assertNotNull($spa);
        $this->assertEquals('active', $spa->status);

        $owner = User::where('email', 'command-owner@yospa.test')->first();
        $this->assertNotNull($owner);
        $this->assertEquals($spa->id, $owner->spa_id);
        $this->assertEquals('owner', $owner->role);
        $this->assertTrue($owner->is_active);
        $this->assertTrue(Hash::check('secretpass123', $owner->password));
    }

    public function test_create_spa_command_handles_slug_collision(): void
    {
        // First creation with slug 'oasis'
        Spa::create([
            'name'   => 'Oasis',
            'slug'   => 'oasis',
            'status' => 'active',
        ]);

        $this->artisan('yospa:create-spa', [
            '--name'        => 'Oasis',
            '--owner-name'  => 'Oasis Two Owner',
            '--owner-email' => 'oasis2@yospa.test',
        ])
            ->expectsQuestion('Owner Password (min 8 characters)', 'secretpass123')
            ->expectsQuestion('Confirm Owner Password', 'secretpass123')
            ->assertExitCode(0);

        $secondSpa = Spa::where('slug', 'oasis-1')->first();
        $this->assertNotNull($secondSpa);
    }

    public function test_create_spa_command_rejects_duplicate_email(): void
    {
        $this->artisan('yospa:create-spa', [
            '--name'        => 'Duplicate Email Spa',
            '--owner-name'  => 'Duplicate Owner',
            '--owner-email' => 'active-owner@yospa.test', // Already exists
        ])
            ->expectsOutput("A user with email 'active-owner@yospa.test' already exists.")
            ->assertExitCode(1);
    }

    public function test_list_spas_command(): void
    {
        // Create client and reservation for active spa
        $client = Client::create([
            'spa_id'    => $this->activeSpa->id,
            'full_name' => 'John Doe',
            'phone'     => '1234567890',
        ]);

        $service = Service::create([
            'spa_id'           => $this->activeSpa->id,
            'name'             => 'Test Massage',
            'duration_minutes' => 60,
            'price'            => 50.00,
        ]);

        Reservation::create([
            'spa_id'      => $this->activeSpa->id,
            'client_id'   => $client->id,
            'service_id'  => $service->id,
            'start_time'  => now()->addDay(),
            'end_time'    => now()->addDay()->addHour(),
            'total_price' => 50.00,
        ]);

        $this->artisan('yospa:list-spas')
            ->assertExitCode(0);
    }

    public function test_reset_owner_password_command(): void
    {
        // Issue token to verify revocation
        $token = $this->activeOwner->createToken('active-token')->plainTextToken;
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->activeOwner->id,
        ]);

        $this->artisan('yospa:reset-owner-password', [
            'email' => 'active-owner@yospa.test',
        ])
            ->expectsQuestion('New Password (min 8 characters)', 'newsecret123')
            ->expectsQuestion('Confirm New Password', 'newsecret123')
            ->assertExitCode(0);

        // Verify password changed
        $this->activeOwner->refresh();
        $this->assertTrue(Hash::check('newsecret123', $this->activeOwner->password));

        // Verify tokens revoked
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->activeOwner->id,
        ]);
    }

    public function test_reset_owner_password_rejects_staff_user(): void
    {
        $staff = User::create([
            'spa_id'    => $this->activeSpa->id,
            'name'      => 'Staff User',
            'email'     => 'staff@yospa.test',
            'password'  => bcrypt('password123'),
            'role'      => 'staff',
            'is_active' => true,
        ]);

        $this->artisan('yospa:reset-owner-password', [
            'email' => 'staff@yospa.test',
        ])
            ->expectsOutput("User 'staff@yospa.test' has role 'staff'. Password reset via this command is restricted to owners and admins.")
            ->assertExitCode(1);
    }

    public function test_set_spa_status_command(): void
    {
        $this->artisan('yospa:set-spa-status', [
            'spa'    => $this->activeSpa->slug,
            'status' => 'suspended',
        ])->assertExitCode(0);

        $this->activeSpa->refresh();
        $this->assertEquals('suspended', $this->activeSpa->status);

        // Re-activate by ID
        $this->artisan('yospa:set-spa-status', [
            'spa'    => (string) $this->activeSpa->id,
            'status' => 'active',
        ])->assertExitCode(0);

        $this->activeSpa->refresh();
        $this->assertEquals('active', $this->activeSpa->status);

        // Reject invalid status
        $this->artisan('yospa:set-spa-status', [
            'spa'    => $this->activeSpa->slug,
            'status' => 'invalid_status',
        ])->assertExitCode(1);
    }
}
