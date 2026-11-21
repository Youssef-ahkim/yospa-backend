<?php

namespace Tests\Feature;

use App\Models\Spa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
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
            'name'   => 'Password Test Spa',
            'slug'   => 'password-test-spa',
            'status' => 'active',
        ]);

        $this->owner = User::create([
            'spa_id'    => $this->spa->id,
            'name'      => 'Owner User',
            'email'     => 'owner@yospa.test',
            'password'  => 'oldpassword123',
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'spa_id'    => $this->spa->id,
            'name'      => 'Admin User',
            'email'     => 'admin@yospa.test',
            'password'  => 'oldpassword123',
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'spa_id'    => $this->spa->id,
            'name'      => 'Staff User',
            'email'     => 'staff@yospa.test',
            'password'  => 'oldpassword123',
            'role'      => 'staff',
            'is_active' => true,
        ]);
    }

    public function test_happy_path_changes_password_revokes_other_tokens_keeps_current(): void
    {
        // Issue two tokens: current device and other device
        $currentTokenObj = $this->owner->createToken('current-device');
        $currentToken = $currentTokenObj->plainTextToken;

        $otherTokenObj = $this->owner->createToken('other-device');
        $otherToken = $otherTokenObj->plainTextToken;

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $currentTokenObj->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherTokenObj->accessToken->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $currentToken)
            ->postJson('/api/user/password', [
                'current_password'      => 'oldpassword123',
                'password'              => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Password updated successfully',
        ]);

        // Current token is preserved and still works
        $userCheck = $this->withHeader('Authorization', 'Bearer ' . $currentToken)
            ->getJson('/api/user');
        $userCheck->assertStatus(200);
        $userCheck->assertJson(['email' => 'owner@yospa.test']);

        // Other token was revoked
        auth()->forgetGuards();
        $otherTokenCheck = $this->withHeader('Authorization', 'Bearer ' . $otherToken)
            ->getJson('/api/user');
        $otherTokenCheck->assertStatus(401);

        // Can log in with NEW password
        $newLogin = $this->postJson('/api/login', [
            'email'    => 'owner@yospa.test',
            'password' => 'newpassword123',
        ]);
        $newLogin->assertStatus(200);

        // Cannot log in with OLD password
        $oldLogin = $this->postJson('/api/login', [
            'email'    => 'owner@yospa.test',
            'password' => 'oldpassword123',
        ]);
        $oldLogin->assertStatus(422);
    }

    public function test_wrong_current_password_returns_422_and_leaves_session_valid(): void
    {
        $token = $this->owner->createToken('current-device')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/password', [
                'current_password'      => 'wrongpassword',
                'password'              => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ]);

        // Returns 422, not 401 or 403
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'current_password' => 'The current password is incorrect.',
        ]);

        // Session remains valid
        $userCheck = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user');
        $userCheck->assertStatus(200);

        // Password was not changed
        $this->assertTrue(Hash::check('oldpassword123', $this->owner->fresh()->password));
    }

    public function test_non_database_transient_token_handled_safely(): void
    {
        // When authenticated via Sanctum::actingAs(), currentAccessToken() is a TransientToken, not a PersonalAccessToken
        \Laravel\Sanctum\Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/user/password', [
            'current_password'      => 'oldpassword123',
            'password'              => 'newtransientpass123',
            'password_confirmation' => 'newtransientpass123',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Password updated successfully',
        ]);
        $this->assertTrue(Hash::check('newtransientpass123', $this->owner->fresh()->password));
    }

    public function test_same_as_current_password_is_rejected(): void
    {
        $token = $this->owner->createToken('current-device')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/password', [
                'current_password'      => 'oldpassword123',
                'password'              => 'oldpassword123',
                'password_confirmation' => 'oldpassword123',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'password' => 'The new password must be different from the current password.',
        ]);
    }

    public function test_mismatched_password_confirmation_is_rejected(): void
    {
        $token = $this->owner->createToken('current-device')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/password', [
                'current_password'      => 'oldpassword123',
                'password'              => 'newpassword123',
                'password_confirmation' => 'differentconfirmation',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_staff_and_admin_roles_can_change_password(): void
    {
        // Admin
        $adminToken = $this->admin->createToken('admin-token')->plainTextToken;
        $adminRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/user/password', [
                'current_password'      => 'oldpassword123',
                'password'              => 'adminnewpass123',
                'password_confirmation' => 'adminnewpass123',
            ]);
        $adminRes->assertStatus(200);
        $this->assertTrue(Hash::check('adminnewpass123', $this->admin->fresh()->password));

        // Forget cached auth guards between requests with different users
        auth()->forgetGuards();

        // Staff
        $staffToken = $this->staff->createToken('staff-token')->plainTextToken;
        $staffRes = $this->withHeader('Authorization', 'Bearer ' . $staffToken)
            ->postJson('/api/user/password', [
                'current_password'      => 'oldpassword123',
                'password'              => 'staffnewpass123',
                'password_confirmation' => 'staffnewpass123',
            ]);
        $staffRes->assertStatus(200);
        $this->assertTrue(Hash::check('staffnewpass123', $this->staff->fresh()->password));
    }

    public function test_rate_limiting_throttles_after_5_attempts_per_user(): void
    {
        $token = $this->owner->createToken('throttle-token')->plainTextToken;
        RateLimiter::clear((string) $this->owner->id);

        for ($i = 0; $i < 5; $i++) {
            $res = $this->withHeader('Authorization', 'Bearer ' . $token)
                ->postJson('/api/user/password', [
                    'current_password'      => 'wrongpassword',
                    'password'              => 'newpass12345',
                    'password_confirmation' => 'newpass12345',
                ]);
            $res->assertStatus(422);
        }

        // 6th attempt is throttled
        $throttled = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/password', [
                'current_password'      => 'oldpassword123',
                'password'              => 'newpass12345',
                'password_confirmation' => 'newpass12345',
            ]);

        $throttled->assertStatus(429);
        $throttled->assertJson([
            'message' => 'Too many password change attempts. Please try again later.',
        ]);
        $this->assertTrue($throttled->headers->has('Retry-After'));
    }

    public function test_suspended_spa_user_is_blocked_by_middleware(): void
    {
        $suspendedSpa = Spa::create([
            'name'   => 'Suspended Password Spa',
            'slug'   => 'suspended-pw-spa',
            'status' => 'suspended',
        ]);

        $suspendedUser = User::create([
            'spa_id'    => $suspendedSpa->id,
            'name'      => 'Suspended User',
            'email'     => 'suspended-pw@yospa.test',
            'password'  => 'oldpassword123',
            'role'      => 'staff',
            'is_active' => true,
        ]);

        $token = $suspendedUser->createToken('suspended-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/user/password', [
                'current_password'      => 'oldpassword123',
                'password'              => 'newpass12345',
                'password_confirmation' => 'newpass12345',
            ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Your account has been deactivated.',
        ]);
    }
}
