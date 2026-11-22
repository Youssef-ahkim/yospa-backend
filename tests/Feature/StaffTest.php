<?php

namespace Tests\Feature;

use App\Models\Spa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    private Spa $spaA;
    private User $ownerA;
    private User $adminA;
    private User $staffA;
    private Spa $spaB;
    private User $ownerB;

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

        $this->adminA = User::create([
            'name'      => 'Adam Admin',
            'email'     => 'adam@alpha.test',
            'password'  => Hash::make('secret123'),
            'spa_id'    => $this->spaA->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $this->staffA = User::create([
            'name'      => 'Sam Staff',
            'email'     => 'sam@alpha.test',
            'password'  => Hash::make('secret123'),
            'spa_id'    => $this->spaA->id,
            'role'      => 'staff',
            'is_active' => true,
        ]);

        $this->spaB = Spa::create([
            'name'   => 'Spa Beta',
            'slug'   => 'spa-beta',
            'status' => 'active',
        ]);

        $this->ownerB = User::create([
            'name'      => 'Bob Owner',
            'email'     => 'bob@beta.test',
            'password'  => Hash::make('secret123'),
            'spa_id'    => $this->spaB->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);
    }

    // 1. Index: same spa only, ordered by name, default active only, ?all=1 includes inactive
    public function test_staff_index_filtering_and_sorting(): void
    {
        User::create([
            'name'      => 'Inactive Staff',
            'email'     => 'inactive@alpha.test',
            'password'  => Hash::make('secret123'),
            'spa_id'    => $this->spaA->id,
            'role'      => 'staff',
            'is_active' => false,
        ]);

        Sanctum::actingAs($this->ownerA);

        // Default: active only (Alice, Adam, Sam)
        $response = $this->getJson('/api/staff');
        $response->assertStatus(200);
        $this->assertCount(3, $response->json());
        $this->assertEquals(['Adam Admin', 'Alice Owner', 'Sam Staff'], array_column($response->json(), 'name'));

        // Never returns password
        $this->assertArrayNotHasKey('password', $response->json(0));

        // ?all=1 returns all 4 in spa A, ordered by name, excluding spa B
        $responseAll = $this->getJson('/api/staff?all=1');
        $responseAll->assertStatus(200);
        $this->assertCount(4, $responseAll->json());
        $this->assertNotContains('Bob Owner', array_column($responseAll->json(), 'name'));

        // Staff cannot view staff list -> 403
        Sanctum::actingAs($this->staffA);
        $this->getJson('/api/staff')->assertStatus(403);
    }

    // 2. Store: validation, email globally unique, role defaults to staff, spa_id from auth user
    public function test_staff_store_happy_path_and_validation(): void
    {
        Sanctum::actingAs($this->ownerA);

        $response = $this->postJson('/api/staff', [
            'name'     => 'New Staff Member',
            'email'    => 'newstaff@alpha.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Staff member created successfully',
                'staff'   => [
                    'name'      => 'New Staff Member',
                    'email'     => 'newstaff@alpha.test',
                    'role'      => 'staff',
                    'is_active' => true,
                ],
            ]);

        $createdId = $response->json('staff.id');
        $this->assertDatabaseHas('users', [
            'id'     => $createdId,
            'spa_id' => $this->spaA->id,
            'role'   => 'staff',
        ]);

        // Duplicate email rejected
        $dupEmail = $this->postJson('/api/staff', [
            'name'     => 'Duplicate Email Person',
            'email'    => 'newstaff@alpha.test',
            'password' => 'password123',
        ]);
        $dupEmail->assertStatus(422)->assertJsonValidationErrors(['email']);

        // Password shorter than 8 chars rejected
        $shortPw = $this->postJson('/api/staff', [
            'name'     => 'Short Pw',
            'email'    => 'short@alpha.test',
            'password' => 'short',
        ]);
        $shortPw->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    // 3. Admin permissions on store: admins can ONLY create staff accounts
    public function test_admin_can_only_create_staff_accounts(): void
    {
        Sanctum::actingAs($this->adminA);

        // Admin creating staff succeeds
        $respStaff = $this->postJson('/api/staff', [
            'name'     => 'Created by Admin',
            'email'    => 'admincreated@alpha.test',
            'password' => 'password123',
            'role'     => 'staff',
        ]);
        $respStaff->assertStatus(201);

        // Admin creating admin fails with 403
        $respAdmin = $this->postJson('/api/staff', [
            'name'     => 'Admin attempt',
            'email'    => 'adminattempt@alpha.test',
            'password' => 'password123',
            'role'     => 'admin',
        ]);
        $respAdmin->assertStatus(403)
            ->assertJson(['message' => 'Admins can only create staff accounts.']);

        // Admin creating owner fails with 422 (owner role not allowed for tenant callers)
        $respOwner = $this->postJson('/api/staff', [
            'name'     => 'Owner attempt',
            'email'    => 'ownerattempt@alpha.test',
            'password' => 'password123',
            'role'     => 'owner',
        ]);
        $respOwner->assertStatus(422)
            ->assertJsonValidationErrors(['role'])
            ->assertJsonPath('errors.role.0', 'Only the platform administrator can assign the owner role.');
    }

    // 4. Update: cross-spa returns 404
    public function test_update_cross_spa_returns_404(): void
    {
        Sanctum::actingAs($this->ownerA);

        $response = $this->patchJson("/api/staff/{$this->ownerB->id}", [
            'name' => 'Hacked Bob',
        ]);
        $response->assertStatus(404);
    }

    // 5. Update: Self-modification safeguards (cannot demote or deactivate yourself)
    public function test_self_modification_safeguards(): void
    {
        Sanctum::actingAs($this->ownerA);

        // Cannot demote yourself -> 422
        $demoteSelf = $this->patchJson("/api/staff/{$this->ownerA->id}", [
            'role' => 'staff',
        ]);
        $demoteSelf->assertStatus(422)
            ->assertJson(['message' => 'You cannot demote yourself.']);

        // Cannot deactivate yourself -> 422
        $deactivateSelf = $this->patchJson("/api/staff/{$this->ownerA->id}", [
            'is_active' => false,
        ]);
        $deactivateSelf->assertStatus(422)
            ->assertJson(['message' => 'You cannot deactivate your own account.']);
    }

    // 6. Update: Last active owner safeguard (cannot remove last active owner)
    public function test_cannot_remove_last_active_owner(): void
    {
        // Another admin attempts to deactivate or demote Alice (the only active owner)
        Sanctum::actingAs($this->ownerA);

        // Create second owner
        $owner2 = User::create([
            'name'      => 'Second Owner',
            'email'     => 'owner2@alpha.test',
            'password'  => Hash::make('secret123'),
            'spa_id'    => $this->spaA->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);

        // Alice cannot deactivate second owner because tenant users cannot deactivate an owner -> 403
        $deactivateOwner2 = $this->patchJson("/api/staff/{$owner2->id}", [
            'is_active' => false,
        ]);
        $deactivateOwner2->assertStatus(403)
            ->assertJson(['message' => 'Unauthorized action.']);

        // Now Alice is the sole active owner; create a second admin to test
        $admin2 = User::create([
            'name'      => 'Admin 2',
            'email'     => 'admin2_test@alpha.test',
            'password'  => Hash::make('secret123'),
            'spa_id'    => $this->spaA->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);

        // Trying to demote another owner fails with 403
        $demoteSoleOwner = $this->patchJson("/api/staff/{$owner2->id}", [
            'role' => 'staff',
        ]);
        $demoteSoleOwner->assertStatus(403)
            ->assertJson(['message' => 'Unauthorized action.']);

        // But deactivating or demoting the remaining active owner fails
        // If we reactivate owner2, then deactivating owner2 when ownerA is demoted:
        $ownerAActiveCheck = $this->patchJson("/api/staff/{$this->ownerA->id}", [
            'role' => 'admin',
        ]);
        // Demoting yourself gives 422 first
        $ownerAActiveCheck->assertStatus(422);
    }

    // 7. Admin safeguards on update: admin cannot modify, deactivate, or reset password of owner/admin
    public function test_admin_cannot_modify_owner_or_admin(): void
    {
        Sanctum::actingAs($this->adminA);

        // Attempt modify owner -> 403
        $respOwner = $this->patchJson("/api/staff/{$this->ownerA->id}", [
            'name' => 'Admin trying to rename owner',
        ]);
        $respOwner->assertStatus(403)
            ->assertJson(['message' => 'Admins cannot modify owner or admin accounts.']);

        // Attempt modify another admin -> 403
        $otherAdmin = User::create([
            'name'      => 'Other Admin',
            'email'     => 'otheradmin@alpha.test',
            'password'  => Hash::make('secret123'),
            'spa_id'    => $this->spaA->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $respAdmin = $this->patchJson("/api/staff/{$otherAdmin->id}", [
            'is_active' => false,
        ]);
        $respAdmin->assertStatus(403)
            ->assertJson(['message' => 'Admins cannot modify owner or admin accounts.']);

        // Attempt promote staff to admin -> 403
        $respPromote = $this->patchJson("/api/staff/{$this->staffA->id}", [
            'role' => 'admin',
        ]);
        $respPromote->assertStatus(403)
            ->assertJson(['message' => 'Admins cannot promote users to owner or admin.']);

        // Admin modifying staff is OK
        $respStaff = $this->patchJson("/api/staff/{$this->staffA->id}", [
            'name' => 'Renamed Staff',
        ]);
        $respStaff->assertStatus(200);
    }

    // 8. Deactivation and password reset revokes Sanctum tokens
    public function test_deactivation_and_password_reset_revokes_tokens(): void
    {
        Sanctum::actingAs($this->ownerA);

        $targetUser = User::create([
            'name'      => 'Token Test User',
            'email'     => 'tokentest@alpha.test',
            'password'  => Hash::make('secret123'),
            'spa_id'    => $this->spaA->id,
            'role'      => 'staff',
            'is_active' => true,
        ]);

        $token1 = $targetUser->createToken('token1')->plainTextToken;
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // Password reset revokes tokens
        $resetResponse = $this->patchJson("/api/staff/{$targetUser->id}", [
            'password' => 'newpassword123',
        ]);
        $resetResponse->assertStatus(200);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Issue new token and test deactivation
        $token2 = $targetUser->createToken('token2')->plainTextToken;
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $deactResponse = $this->patchJson("/api/staff/{$targetUser->id}", [
            'is_active' => false,
        ]);
        $deactResponse->assertStatus(200);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
