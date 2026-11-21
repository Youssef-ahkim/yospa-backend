<?php

namespace Tests\Feature;

use App\Models\Spa;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class SuperuserProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Spa $spa;
    private User $owner;

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
    }

    public function test_create_superuser_command_happy_path(): void
    {
        $this->artisan('yospa:create-superuser', [
            '--name'  => 'Platform Superuser',
            '--email' => 'super@yospa.test',
        ])
            ->expectsQuestion('Superuser Password (min 12 characters)', 'supersecret123')
            ->expectsQuestion('Confirm Superuser Password', 'supersecret123')
            ->expectsOutput('Platform superuser created successfully!')
            ->doesntExpectOutput('supersecret123')
            ->assertExitCode(0);

        $superuser = User::where('email', 'super@yospa.test')->first();
        $this->assertNotNull($superuser);
        $this->assertNull($superuser->spa_id);
        $this->assertEquals('superuser', $superuser->role);
        $this->assertTrue($superuser->is_active);
        $this->assertTrue(Hash::check('supersecret123', $superuser->password));
    }

    public function test_create_superuser_refuses_if_superuser_already_exists(): void
    {
        User::create([
            'name'      => 'Existing Superuser',
            'email'     => 'firstsuper@yospa.test',
            'password'  => Hash::make('supersecret123'),
            'spa_id'    => null,
            'role'      => 'superuser',
            'is_active' => true,
        ]);

        $this->artisan('yospa:create-superuser', [
            '--name'  => 'Second Superuser',
            '--email' => 'secondsuper@yospa.test',
        ])
            ->expectsOutput('A platform superuser already exists.')
            ->assertExitCode(1);
    }

    public function test_create_superuser_rejects_duplicate_email(): void
    {
        $this->artisan('yospa:create-superuser', [
            '--name'  => 'Duplicate Email Superuser',
            '--email' => 'owner@yospa.test',
        ])
            ->expectsOutput("A user with email 'owner@yospa.test' already exists.")
            ->assertExitCode(1);
    }

    public function test_create_superuser_validates_min_12_characters_password(): void
    {
        $this->artisan('yospa:create-superuser', [
            '--name'  => 'Password Test Superuser',
            '--email' => 'pwdtest@yospa.test',
        ])
            ->expectsQuestion('Superuser Password (min 12 characters)', 'short1234')
            ->expectsOutput('The password must be at least 12 characters.')
            ->expectsQuestion('Superuser Password (min 12 characters)', 'validpassword123')
            ->expectsQuestion('Confirm Superuser Password', 'mismatch123456')
            ->expectsOutput('Passwords do not match.')
            ->expectsQuestion('Superuser Password (min 12 characters)', 'validpassword123')
            ->expectsQuestion('Confirm Superuser Password', 'validpassword123')
            ->assertExitCode(0);
    }

    public function test_database_enforces_single_superuser_partial_unique_index(): void
    {
        User::create([
            'name'      => 'First Superuser',
            'email'     => 'first@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => null,
            'role'      => 'superuser',
            'is_active' => true,
        ]);

        // Attempting to insert a second superuser directly into the database violates partial unique index
        $this->expectException(QueryException::class);

        User::create([
            'name'      => 'Second Superuser',
            'email'     => 'second@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => null,
            'role'      => 'superuser',
            'is_active' => true,
        ]);
    }

    public function test_reset_owner_password_accepts_superuser_and_revokes_tokens(): void
    {
        $superuser = User::create([
            'name'      => 'Super To Reset',
            'email'     => 'resetme@yospa.test',
            'password'  => Hash::make('oldpassword123'),
            'spa_id'    => null,
            'role'      => 'superuser',
            'is_active' => true,
        ]);

        $token = $superuser->createToken('super-token')->plainTextToken;
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $superuser->id,
        ]);

        $this->artisan('yospa:reset-owner-password', [
            'email' => 'resetme@yospa.test',
        ])
            ->expectsQuestion('New Password (min 8 characters)', 'brandnewpassword12')
            ->expectsQuestion('Confirm New Password', 'brandnewpassword12')
            ->assertExitCode(0);

        $superuser->refresh();
        $this->assertTrue(Hash::check('brandnewpassword12', $superuser->password));

        // Tokens revoked
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $superuser->id,
        ]);
    }

    public function test_superuser_login_token_expires_in_12_hours_and_tenant_token_unchanged(): void
    {
        Carbon::setTestNow('2026-11-21 12:00:00');

        $superuser = User::create([
            'name'      => 'Login Superuser',
            'email'     => 'loginsuper@yospa.test',
            'password'  => Hash::make('superpassword123'),
            'spa_id'    => null,
            'role'      => 'superuser',
            'is_active' => true,
        ]);

        // Superuser login
        $superLogin = $this->postJson('/api/login', [
            'email'    => 'loginsuper@yospa.test',
            'password' => 'superpassword123',
        ]);
        $superLogin->assertStatus(200);

        $superToken = PersonalAccessToken::where('tokenable_id', $superuser->id)->first();
        $this->assertNotNull($superToken);
        $this->assertNotNull($superToken->expires_at);
        $this->assertEquals(
            '2026-11-22 00:00:00',
            $superToken->expires_at->toDateTimeString()
        );

        // Tenant owner login
        $ownerLogin = $this->postJson('/api/login', [
            'email'    => 'owner@yospa.test',
            'password' => 'password123',
        ]);
        $ownerLogin->assertStatus(200);

        $ownerToken = PersonalAccessToken::where('tokenable_id', $this->owner->id)->first();
        $this->assertNotNull($ownerToken);
        $this->assertNull($ownerToken->expires_at);

        Carbon::setTestNow();
    }
}
