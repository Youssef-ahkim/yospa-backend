<?php

namespace Tests\Feature;

use App\Models\Spa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnerProtectionTest extends TestCase
{
    use RefreshDatabase;

    private Spa $spa;
    private User $owner;
    private User $secondOwner;
    private User $staff;
    private User $superuser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spa = Spa::create([
            'name'   => 'Serenity Spa',
            'slug'   => 'serenity-spa',
            'status' => 'active',
        ]);

        $this->owner = User::create([
            'name'      => 'Alice Owner',
            'email'     => 'alice@serenity.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->secondOwner = User::create([
            'name'      => 'Bob Second Owner',
            'email'     => 'bob@serenity.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name'      => 'Sam Staff',
            'email'     => 'sam@serenity.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa->id,
            'role'      => 'staff',
            'is_active' => true,
        ]);

        $this->superuser = User::create([
            'name'      => 'Platform Superuser',
            'email'     => 'super@platform.test',
            'password'  => Hash::make('superuserpassword123'),
            'spa_id'    => null,
            'role'      => 'superuser',
            'is_active' => true,
        ]);
    }

    // 1. Owner creating staff with role=owner gets 422 on role
    public function test_owner_creating_staff_with_role_owner_gets_422(): void
    {
        $token = $this->owner->createToken('owner-token')->plainTextToken;

        // Call without Accept header to verify JSON error response
        $response = $this->call(
            'POST',
            '/api/staff',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'name'     => 'New Owner Attempt',
                'email'    => 'newowner@serenity.test',
                'password' => 'secretpassword123',
                'role'     => 'owner',
            ])
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $response->assertJsonValidationErrors(['role']);
        $response->assertJsonPath('errors.role.0', 'Only the platform administrator can assign the owner role.');
    }

    // 2. Owner updating a staff member to role=owner gets 422
    public function test_owner_updating_staff_member_to_role_owner_gets_422(): void
    {
        $token = $this->owner->createToken('owner-token')->plainTextToken;

        // Call without Accept header
        $response = $this->call(
            'PATCH',
            "/api/staff/{$this->staff->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'role' => 'owner',
            ])
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $response->assertJsonValidationErrors(['role']);
        $response->assertJsonPath('errors.role.0', 'Only the platform administrator can assign the owner role.');
    }

    // 3. Owner editing, deactivating or resetting another owner gets 403
    public function test_owner_editing_deactivating_or_resetting_another_owner_gets_403(): void
    {
        $token = $this->owner->createToken('owner-token')->plainTextToken;

        // 3a. Editing another owner's name/email -> 403
        $editResponse = $this->call(
            'PATCH',
            "/api/staff/{$this->secondOwner->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'name' => 'Renamed Owner',
            ])
        );
        $editResponse->assertStatus(403);
        $this->assertStringContainsString('application/json', $editResponse->headers->get('Content-Type'));
        $editResponse->assertJson(['message' => 'Unauthorized action.']);

        // 3b. Deactivating another owner -> 403
        $deactivateResponse = $this->call(
            'PATCH',
            "/api/staff/{$this->secondOwner->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'is_active' => false,
            ])
        );
        $deactivateResponse->assertStatus(403);
        $this->assertStringContainsString('application/json', $deactivateResponse->headers->get('Content-Type'));
        $deactivateResponse->assertJson(['message' => 'Unauthorized action.']);

        // 3c. Resetting another owner's password -> 403
        $resetResponse = $this->call(
            'PATCH',
            "/api/staff/{$this->secondOwner->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'password' => 'newpassword12345',
            ])
        );
        $resetResponse->assertStatus(403);
        $this->assertStringContainsString('application/json', $resetResponse->headers->get('Content-Type'));
        $resetResponse->assertJson(['message' => 'Unauthorized action.']);
    }

    // 4. Owner creating a normal staff role still works
    public function test_owner_creating_normal_staff_role_still_works(): void
    {
        $token = $this->owner->createToken('owner-token')->plainTextToken;

        $response = $this->call(
            'POST',
            '/api/staff',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'name'     => 'Valid New Staff',
                'email'    => 'validstaff@serenity.test',
                'password' => 'secret12345',
                'role'     => 'staff',
            ])
        );

        $response->assertStatus(201);
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $response->assertJson([
            'message' => 'Staff member created successfully',
            'staff'   => [
                'name'      => 'Valid New Staff',
                'email'     => 'validstaff@serenity.test',
                'role'      => 'staff',
                'is_active' => true,
            ],
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'validstaff@serenity.test',
            'role'  => 'staff',
        ]);
    }

    // 5. Owner changing their own password still works
    public function test_owner_changing_their_own_password_still_works(): void
    {
        $token = $this->owner->createToken('owner-token')->plainTextToken;

        $response = $this->call(
            'POST',
            '/api/user/password',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'current_password'      => 'password123',
                'password'              => 'newownerpassword123',
                'password_confirmation' => 'newownerpassword123',
            ])
        );

        $response->assertStatus(200);
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $response->assertJson([
            'message' => 'Password updated successfully',
        ]);

        $this->assertTrue(Hash::check('newownerpassword123', $this->owner->fresh()->password));
    }

    // 6. Superuser creating a spa with an owner still works
    public function test_superuser_creating_spa_with_owner_still_works(): void
    {
        $token = $this->superuser->createToken('super-token')->plainTextToken;

        $response = $this->call(
            'POST',
            '/api/platform/spas',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'name'           => 'New Super Spa',
                'slug'           => 'new-super-spa',
                'owner_name'     => 'Super Created Owner',
                'owner_email'    => 'superowner@spa.test',
                'owner_password' => 'ownerpassword123',
            ])
        );

        $response->assertStatus(201);
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $response->assertJson([
            'message' => 'Spa created successfully',
            'owner'   => [
                'name'  => 'Super Created Owner',
                'email' => 'superowner@spa.test',
                'role'  => 'owner',
            ],
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'superowner@spa.test',
            'role'  => 'owner',
        ]);
    }

    // 7. Superuser changing a user's role to owner still works
    public function test_superuser_changing_user_role_to_owner_still_works(): void
    {
        $token = $this->superuser->createToken('super-token')->plainTextToken;

        $response = $this->call(
            'PATCH',
            "/api/platform/users/{$this->staff->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'role' => 'owner',
            ])
        );

        $response->assertStatus(200);
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $response->assertJson([
            'message' => 'User updated successfully',
            'user'    => [
                'id'   => $this->staff->id,
                'role' => 'owner',
            ],
        ]);

        $this->assertEquals('owner', $this->staff->fresh()->role);
    }
}
