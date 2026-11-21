<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PlatformAuditLog;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Spa;
use App\Models\Therapist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    private User $superuser;
    private Spa $spa;
    private User $owner;
    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superuser = User::create([
            'name'      => 'Super Admin',
            'email'     => 'superadmin@yospa.test',
            'password'  => Hash::make('SuperSecretPassword123!'),
            'spa_id'    => null,
            'role'      => 'superuser',
            'is_active' => true,
        ]);

        $this->spa = Spa::create([
            'name'   => 'Platform Test Spa',
            'slug'   => 'platform-test-spa',
            'status' => 'active',
        ]);

        $this->owner = User::create([
            'name'      => 'Spa Owner',
            'email'     => 'spaowner@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'name'      => 'Spa Admin',
            'email'     => 'spaadmin@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name'      => 'Spa Staff',
            'email'     => 'spastaff@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa->id,
            'role'      => 'staff',
            'is_active' => true,
        ]);
    }

    public function test_authorization_all_platform_routes_reject_unauthenticated_and_tenant_roles(): void
    {
        $platformEndpoints = [
            ['GET', '/api/platform/stats'],
            ['GET', '/api/platform/spas'],
            ['POST', '/api/platform/spas', [
                'name'           => 'Auth Test Spa',
                'owner_name'     => 'Auth Owner',
                'owner_email'    => 'authowner@yospa.test',
                'owner_password' => 'secret1234',
            ]],
            ['GET', "/api/platform/spas/{$this->spa->id}"],
            ['PATCH', "/api/platform/spas/{$this->spa->id}", ['name' => 'Renamed']],
            ['DELETE', "/api/platform/spas/{$this->spa->id}", ['confirm_slug' => 'platform-test-spa']],
            ['POST', "/api/platform/spas/{$this->spa->id}/users", [
                'name'     => 'New User',
                'email'    => 'newu@yospa.test',
                'password' => 'secret1234',
                'role'     => 'staff',
            ]],
            ['PATCH', "/api/platform/users/{$this->staff->id}", ['name' => 'Updated']],
            ['GET', '/api/platform/audit'],
        ];

        // 1. Unauthenticated requests receive 401
        foreach ($platformEndpoints as $endpoint) {
            $method  = $endpoint[0];
            $uri     = $endpoint[1];
            $payload = $endpoint[2] ?? [];

            $response = match ($method) {
                'GET'    => $this->getJson($uri),
                'POST'   => $this->postJson($uri, $payload),
                'PATCH'  => $this->patchJson($uri, $payload),
                'DELETE' => $this->deleteJson($uri, $payload),
            };

            $response->assertStatus(401)
                ->assertJson(['message' => 'Unauthenticated.']);
        }

        // 2. Tenant roles (owner, admin, staff) receive 403 Forbidden
        foreach ([$this->owner, $this->admin, $this->staff] as $tenantUser) {
            Sanctum::actingAs($tenantUser);

            foreach ($platformEndpoints as $endpoint) {
                $method  = $endpoint[0];
                $uri     = $endpoint[1];
                $payload = $endpoint[2] ?? [];

                $response = match ($method) {
                    'GET'    => $this->getJson($uri),
                    'POST'   => $this->postJson($uri, $payload),
                    'PATCH'  => $this->patchJson($uri, $payload),
                    'DELETE' => $this->deleteJson($uri, $payload),
                };

                $response->assertStatus(403)
                    ->assertJson(['message' => 'Unauthorized action.']);
            }
        }

        // 3. Superuser is rejected with 403 on tenant routes
        Sanctum::actingAs($this->superuser);
        $this->getJson('/api/clients')->assertStatus(403);
        $this->getJson('/api/reservations')->assertStatus(403);
        $this->getJson('/api/services')->assertStatus(403);
        $this->getJson('/api/therapists')->assertStatus(403);
        $this->getJson('/api/staff')->assertStatus(403);
    }

    public function test_stats_and_spas_index_and_show(): void
    {
        Sanctum::actingAs($this->superuser);

        // Add a second suspended spa
        $suspendedSpa = Spa::create([
            'name'   => 'Suspended Oasis',
            'slug'   => 'suspended-oasis',
            'status' => 'suspended',
        ]);

        $client = Client::create([
            'spa_id'    => $this->spa->id,
            'full_name' => 'Alice Client',
            'phone'     => '+212600000001',
        ]);

        $service = Service::create([
            'spa_id'           => $this->spa->id,
            'name'             => 'Signature Massage',
            'duration_minutes' => 60,
            'price'            => 200,
            'is_active'        => true,
        ]);

        Reservation::create([
            'spa_id'      => $this->spa->id,
            'client_id'   => $client->id,
            'service_id'  => $service->id,
            'start_time'  => now()->addDay(),
            'end_time'    => now()->addDay()->addHour(),
            'total_price' => 200,
        ]);

        // GET /api/platform/stats
        $statsRes = $this->getJson('/api/platform/stats');
        $statsRes->assertStatus(200)
            ->assertJson([
                'spas' => [
                    'total'     => 2,
                    'active'    => 1,
                    'suspended' => 1,
                ],
                'users'        => 3,
                'clients'      => 1,
                'reservations' => 1,
            ]);

        // GET /api/platform/spas without filters
        $spasRes = $this->getJson('/api/platform/spas');
        $spasRes->assertStatus(200);
        $this->assertCount(2, $spasRes->json());

        // Filter by status=suspended
        $filteredStatus = $this->getJson('/api/platform/spas?status=suspended');
        $filteredStatus->assertStatus(200);
        $this->assertCount(1, $filteredStatus->json());
        $this->assertEquals('suspended-oasis', $filteredStatus->json('0.slug'));

        // Search by name
        $searchName = $this->getJson('/api/platform/spas?search=platform');
        $searchName->assertStatus(200);
        $this->assertCount(1, $searchName->json());
        $this->assertEquals($this->spa->id, $searchName->json('0.id'));

        // Search by slug
        $searchSlug = $this->getJson('/api/platform/spas?search=suspended-oasis');
        $searchSlug->assertStatus(200);
        $this->assertCount(1, $searchSlug->json());

        // Search by owner email
        $searchEmail = $this->getJson('/api/platform/spas?search=SPAOWNER@YOSPA.TEST');
        $searchEmail->assertStatus(200);
        $this->assertCount(1, $searchEmail->json());
        $this->assertEquals($this->spa->id, $searchEmail->json('0.id'));

        // GET /api/platform/spas/{id}
        $showRes = $this->getJson("/api/platform/spas/{$this->spa->id}");
        $showRes->assertStatus(200)
            ->assertJsonStructure([
                'spa'    => ['id', 'name', 'slug', 'status', 'created_at', 'updated_at'],
                'counts' => ['users', 'clients', 'services', 'therapists', 'reservations'],
                'users'  => [
                    '*' => ['id', 'name', 'email', 'role', 'is_active'],
                ],
            ]);
        $this->assertEquals(3, $showRes->json('counts.users'));
        $this->assertEquals(1, $showRes->json('counts.clients'));
    }

    public function test_create_spa_and_owner_validation_slug_suffix_and_transaction(): void
    {
        Sanctum::actingAs($this->superuser);

        // 1. Password min 8 rejected
        $shortPassRes = $this->postJson('/api/platform/spas', [
            'name'           => 'Short Pass Spa',
            'owner_name'     => 'Short Owner',
            'owner_email'    => 'shortowner@yospa.test',
            'owner_password' => 'short',
        ]);
        $shortPassRes->assertStatus(422)
            ->assertJsonValidationErrors(['owner_password']);

        // 2. Duplicate owner email in different case is rejected
        $dupEmailRes = $this->postJson('/api/platform/spas', [
            'name'           => 'Duplicate Email Spa',
            'owner_name'     => 'Dup Owner',
            'owner_email'    => 'SPAOWNER@YOSPA.TEST', // already exists in lower case
            'owner_password' => 'validpassword123',
        ]);
        $dupEmailRes->assertStatus(422)
            ->assertJsonValidationErrors(['owner_email']);

        // 3. Happy path with automatic slug suffixing
        $spa1 = $this->postJson('/api/platform/spas', [
            'name'           => 'Atlas Hammam',
            'owner_name'     => 'Atlas Owner 1',
            'owner_email'    => 'atlas1@yospa.test',
            'owner_password' => 'validpassword123',
        ]);
        $spa1->assertStatus(201);
        $this->assertEquals('atlas-hammam', $spa1->json('spa.slug'));

        $spa2 = $this->postJson('/api/platform/spas', [
            'name'           => 'Atlas Hammam',
            'owner_name'     => 'Atlas Owner 2',
            'owner_email'    => 'atlas2@yospa.test',
            'owner_password' => 'validpassword123',
        ]);
        $spa2->assertStatus(201);
        $this->assertEquals('atlas-hammam-1', $spa2->json('spa.slug'));

        // 4. Single transaction rollback on failure
        $initialSpaCount = Spa::count();
        $initialUserCount = User::count();

        // Attempting to create spa where an DB error occurs or constraint fails rolls back completely
        try {
            DB::transaction(function () {
                Spa::create([
                    'name'   => 'Rollback Spa',
                    'slug'   => 'rollback-spa',
                    'status' => 'active',
                ]);
                throw new \Exception('Simulated transaction failure');
            });
        } catch (\Exception $e) {
            // caught
        }

        $this->assertEquals($initialSpaCount, Spa::count());
        $this->assertEquals($initialUserCount, User::count());
    }

    public function test_update_spa_and_suspension_signs_out_users(): void
    {
        $superToken = $this->superuser->createToken('super-session')->plainTextToken;

        // Update name, slug uniqueness
        $patchRes = $this->withToken($superToken)->patchJson("/api/platform/spas/{$this->spa->id}", [
            'name'   => 'Updated Luxury Spa',
            'slug'   => 'updated-luxury-spa',
            'status' => 'active',
        ]);
        $patchRes->assertStatus(200)
            ->assertJson([
                'spa' => [
                    'name' => 'Updated Luxury Spa',
                    'slug' => 'updated-luxury-spa',
                ],
            ]);

        // Issue token for the spa owner
        $ownerToken = $this->owner->createToken('owner-session')->plainTextToken;

        auth()->forgetGuards();

        // Owner request succeeds while spa is active
        $this->withToken($ownerToken)->getJson('/api/user')->assertStatus(200);

        auth()->forgetGuards();

        // Suspend spa via platform API
        $suspendRes = $this->withToken($superToken)->patchJson("/api/platform/spas/{$this->spa->id}", [
            'status' => 'suspended',
        ]);
        $suspendRes->assertStatus(200);

        auth()->forgetGuards();

        // Owner request now gets 403 deactivated and token is deleted
        $blockedOwner = $this->withToken($ownerToken)->getJson('/api/user');
        $blockedOwner->assertStatus(403)
            ->assertJson(['message' => 'Your account has been deactivated.']);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->owner->id,
        ]);
    }

    public function test_delete_spa_guards_and_success_with_foreign_keys_on(): void
    {
        // Enforce SQLite Foreign Keys
        DB::statement('PRAGMA foreign_keys = ON;');

        Sanctum::actingAs($this->superuser);

        $spa = Spa::create([
            'name'   => 'Deletable Spa',
            'slug'   => 'deletable-spa',
            'status' => 'active',
        ]);

        $owner = User::create([
            'name'      => 'Del Owner',
            'email'     => 'delowner@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $spa->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);
        $ownerToken = $owner->createToken('del-token')->plainTextToken;

        $client = Client::create([
            'spa_id'    => $spa->id,
            'full_name' => 'Del Client',
            'phone'     => '0600000099',
        ]);

        $service = Service::create([
            'spa_id'           => $spa->id,
            'name'             => 'Del Service',
            'duration_minutes' => 60,
            'price'            => 100,
            'is_active'        => true,
        ]);

        $therapist = Therapist::create([
            'spa_id'    => $spa->id,
            'name'      => 'Del Therapist',
            'is_active' => true,
        ]);

        // Audit log created for this spa
        $auditLog = PlatformAuditLog::create([
            'actor_user_id' => $this->superuser->id,
            'action'        => 'spa.test_action',
            'subject_type'  => 'spa',
            'subject_id'    => $spa->id,
            'spa_id'        => $spa->id,
            'meta'          => ['info' => 'before_delete'],
            'ip'            => '127.0.0.1',
            'created_at'    => now(),
        ]);

        // 1. 409 when spa is active
        $this->deleteJson("/api/platform/spas/{$spa->id}", ['confirm_slug' => 'deletable-spa'])
            ->assertStatus(409)
            ->assertJson(['message' => 'Cannot delete spa. Spa must be suspended and have 0 reservations.']);

        // Suspend spa
        $spa->status = 'suspended';
        $spa->save();

        // 2. 409 when spa has reservations
        $reservation = Reservation::create([
            'spa_id'       => $spa->id,
            'client_id'    => $client->id,
            'service_id'   => $service->id,
            'therapist_id' => $therapist->id,
            'start_time'   => now()->addDay(),
            'end_time'     => now()->addDay()->addHour(),
            'total_price'  => 100,
        ]);

        $this->deleteJson("/api/platform/spas/{$spa->id}", ['confirm_slug' => 'deletable-spa'])
            ->assertStatus(409)
            ->assertJson(['message' => 'Cannot delete spa. Spa must be suspended and have 0 reservations.']);

        // Remove reservation to satisfy condition
        $reservation->delete();

        // 3. 422 on wrong confirm_slug
        $this->deleteJson("/api/platform/spas/{$spa->id}", ['confirm_slug' => 'wrong-slug'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirm_slug']);

        // 4. Success path with PRAGMA foreign_keys = ON
        $deleteRes = $this->deleteJson("/api/platform/spas/{$spa->id}", ['confirm_slug' => 'deletable-spa']);
        $deleteRes->assertStatus(200)
            ->assertJson(['message' => 'Spa deleted successfully']);

        // Assert spa and tenant records are deleted
        $this->assertDatabaseMissing('spas', ['id' => $spa->id]);
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
        $this->assertDatabaseMissing('services', ['id' => $service->id]);
        $this->assertDatabaseMissing('therapists', ['id' => $therapist->id]);

        // Assert user tokens are deleted
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $owner->id]);

        // Assert platform_audit_logs.spa_id was NOT a cascading foreign key:
        // Prior audit log still exists, and its spa_id was set to null by ON DELETE SET NULL
        $this->assertDatabaseHas('platform_audit_logs', [
            'id'     => $auditLog->id,
            'spa_id' => null,
        ]);

        // The deletion audit log itself exists
        $this->assertDatabaseHas('platform_audit_logs', [
            'action'       => 'spa.delete',
            'subject_type' => 'spa',
            'subject_id'   => $spa->id,
            'spa_id'       => null,
        ]);
    }

    public function test_add_user_and_patch_user_guards_and_token_revocation(): void
    {
        Sanctum::actingAs($this->superuser);

        // 1. Add user to spa
        $addUserRes = $this->postJson("/api/platform/spas/{$this->spa->id}/users", [
            'name'     => 'New Staff Member',
            'email'    => 'newstaff@yospa.test',
            'password' => 'secretpass123',
            'role'     => 'staff',
        ]);
        $addUserRes->assertStatus(201)
            ->assertJson([
                'user' => [
                    'name'      => 'New Staff Member',
                    'email'     => 'newstaff@yospa.test',
                    'role'      => 'staff',
                    'is_active' => true,
                ],
            ]);
        $newUserId = $addUserRes->json('user.id');

        // 2. PATCH user: 404 for superuser account and user without spa
        $this->patchJson("/api/platform/users/{$this->superuser->id}", ['name' => 'Hacked'])
            ->assertStatus(404);

        $orphanUser = User::create([
            'name'      => 'Orphan',
            'email'     => 'orphan@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => null,
            'role'      => 'staff',
            'is_active' => true,
        ]);
        $this->patchJson("/api/platform/users/{$orphanUser->id}", ['name' => 'Hacked Orphan'])
            ->assertStatus(404);

        // 3. Email unique ignoring self, case-insensitive
        // Keeping own email in uppercase works
        $ownEmailRes = $this->patchJson("/api/platform/users/{$newUserId}", [
            'email' => 'NEWSTAFF@YOSPA.TEST',
        ]);
        $ownEmailRes->assertStatus(200);

        // Duplicate of another user in different case returns 422
        $dupEmailRes = $this->patchJson("/api/platform/users/{$newUserId}", [
            'email' => 'SPAOWNER@YOSPA.TEST',
        ]);
        $dupEmailRes->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // 4. 409 for deactivating or demoting the last active owner of that spa
        $demoteRes = $this->patchJson("/api/platform/users/{$this->owner->id}", [
            'role' => 'admin',
        ]);
        $demoteRes->assertStatus(409)
            ->assertJson(['message' => 'Cannot deactivate or change role of the last active owner.']);

        $deactRes = $this->patchJson("/api/platform/users/{$this->owner->id}", [
            'is_active' => false,
        ]);
        $deactRes->assertStatus(409)
            ->assertJson(['message' => 'Cannot deactivate or change role of the last active owner.']);

        // 5. Tokens revoked on deactivation, role change, and password reset
        $targetUser = User::find($newUserId);

        // Token revoked on password reset
        $t1 = $targetUser->createToken('token-1')->plainTextToken;
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $targetUser->id]);
        $this->patchJson("/api/platform/users/{$targetUser->id}", [
            'password' => 'newpassword123',
        ])->assertStatus(200);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $targetUser->id]);

        // Token revoked on role change
        $t2 = $targetUser->createToken('token-2')->plainTextToken;
        $this->patchJson("/api/platform/users/{$targetUser->id}", [
            'role' => 'admin',
        ])->assertStatus(200);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $targetUser->id]);

        // Token revoked on deactivation
        $t3 = $targetUser->createToken('token-3')->plainTextToken;
        $this->patchJson("/api/platform/users/{$targetUser->id}", [
            'is_active' => false,
        ])->assertStatus(200);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $targetUser->id]);
    }

    public function test_audit_logs_tracked_and_never_leak_secrets(): void
    {
        Sanctum::actingAs($this->superuser);

        $testPassword = 'TestPlainPassword999!';

        // Create spa
        $createSpaRes = $this->postJson('/api/platform/spas', [
            'name'           => 'Secret Leak Test Spa',
            'owner_name'     => 'Secret Owner',
            'owner_email'    => 'secretowner@yospa.test',
            'owner_password' => $testPassword,
        ]);
        $createSpaRes->assertStatus(201);
        $spaId = $createSpaRes->json('spa.id');
        $this->assertStringNotContainsString($testPassword, $createSpaRes->getContent());
        $this->assertStringNotContainsString('$2y$', $createSpaRes->getContent());

        // Create user
        $createUserRes = $this->postJson("/api/platform/spas/{$spaId}/users", [
            'name'     => 'Secret Staff',
            'email'    => 'secretstaff@yospa.test',
            'password' => $testPassword,
            'role'     => 'staff',
        ]);
        $createUserRes->assertStatus(201);
        $userId = $createUserRes->json('user.id');
        $this->assertStringNotContainsString($testPassword, $createUserRes->getContent());
        $this->assertStringNotContainsString('$2y$', $createUserRes->getContent());

        // Update user password
        $updateUserRes = $this->patchJson("/api/platform/users/{$userId}", [
            'password' => 'BrandNewPassword888!',
        ]);
        $updateUserRes->assertStatus(200);
        $this->assertStringNotContainsString('BrandNewPassword888!', $updateUserRes->getContent());
        $this->assertStringNotContainsString('$2y$', $updateUserRes->getContent());

        // Query audit endpoint
        $auditRes = $this->getJson('/api/platform/audit?limit=50');
        $auditRes->assertStatus(200);

        $jsonContent = $auditRes->getContent();
        // Assert no password string appears in audit response or meta
        $this->assertStringNotContainsString($testPassword, $jsonContent);
        $this->assertStringNotContainsString('BrandNewPassword888!', $jsonContent);
        $this->assertStringNotContainsString('$2y$', $jsonContent);

        // Verify ordering: latest first
        $logs = $auditRes->json();
        $this->assertNotEmpty($logs);
        $firstId = $logs[0]['id'];
        $lastId = $logs[count($logs) - 1]['id'];
        $this->assertGreaterThanOrEqual($lastId, $firstId);

        // Verify actor, action, spa_id
        $this->assertEquals($this->superuser->id, $logs[0]['actor_user_id']);
    }
}
