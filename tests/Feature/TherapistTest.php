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

class TherapistTest extends TestCase
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

    // 1. Index: plain array, default active only, ?all=1 includes inactive, ordered by name, readable by all roles
    public function test_therapists_index_filtering_and_role_access(): void
    {
        Therapist::create([
            'spa_id'    => $this->spaA->id,
            'name'      => 'Zara Therapist',
            'is_active' => true,
            'color'     => '#10B981',
        ]);

        Therapist::create([
            'spa_id'    => $this->spaA->id,
            'name'      => 'Bella Therapist',
            'is_active' => true,
            'color'     => '#3B82F6',
        ]);

        Therapist::create([
            'spa_id'    => $this->spaA->id,
            'name'      => 'Inactive Anna',
            'is_active' => false,
            'color'     => '#EF4444',
        ]);

        // Staff can read
        Sanctum::actingAs($this->staffA);

        // Default: active only, ordered by name (Bella, Zara)
        $response = $this->getJson('/api/therapists');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
        $this->assertEquals(['Bella Therapist', 'Zara Therapist'], array_column($response->json(), 'name'));

        // ?all=1: includes inactive, ordered by name (Bella, Inactive Anna, Zara)
        $responseAll = $this->getJson('/api/therapists?all=1');
        $responseAll->assertStatus(200);
        $this->assertCount(3, $responseAll->json());
        $this->assertEquals(['Bella Therapist', 'Inactive Anna', 'Zara Therapist'], array_column($responseAll->json(), 'name'));
    }

    // 2. Store: happy path, validation (color hex, duplicate name case-insensitive), user_id validations
    public function test_therapists_store_validation_and_user_link(): void
    {
        Sanctum::actingAs($this->ownerA);

        // Success with valid user_id and hex color
        $response = $this->postJson('/api/therapists', [
            'name'      => 'Fatima Alami',
            'phone'     => '+212611223344',
            'email'     => 'fatima@therapist.test',
            'color'     => '#8B5CF6',
            'notes'     => 'Specialist in hot stone massage.',
            'user_id'   => $this->staffA->id,
            'is_active' => true,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'message'   => 'Therapist created successfully',
                'therapist' => [
                    'name'    => 'Fatima Alami',
                    'spa_id'  => $this->spaA->id,
                    'color'   => '#8B5CF6',
                    'user_id' => $this->staffA->id,
                ],
            ]);

        $therapistId = $response->json('therapist.id');

        // Duplicate name case-insensitively -> 422
        $dupResponse = $this->postJson('/api/therapists', [
            'name'  => '  fatima alami  ',
            'color' => '#123456',
        ]);
        $dupResponse->assertStatus(422)->assertJsonValidationErrors(['name']);

        // Invalid hex color -> 422
        $badColor = $this->postJson('/api/therapists', [
            'name'  => 'Color Test',
            'color' => 'blue',
        ]);
        $badColor->assertStatus(422)->assertJsonValidationErrors(['color']);

        // Cross-spa user_id -> 422
        $crossUser = $this->postJson('/api/therapists', [
            'name'    => 'Cross User Test',
            'user_id' => $this->ownerB->id,
        ]);
        $crossUser->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        // User already linked to another therapist -> 422
        $alreadyLinked = $this->postJson('/api/therapists', [
            'name'    => 'Second Link Attempt',
            'user_id' => $this->staffA->id,
        ]);
        $alreadyLinked->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        // Staff attempting store -> 403
        Sanctum::actingAs($this->staffA);
        $this->postJson('/api/therapists', ['name' => 'Staff Attempt'])->assertStatus(403);
    }

    // 3. Show & Cross-tenant 404
    public function test_show_and_cross_tenant_isolation(): void
    {
        $therapistA = Therapist::create([
            'spa_id' => $this->spaA->id,
            'name'   => 'Therapist In Spa A',
            'color'  => '#3B82F6',
        ]);

        // Same spa can show
        Sanctum::actingAs($this->staffA);
        $show = $this->getJson("/api/therapists/{$therapistA->id}");
        $show->assertStatus(200)->assertJson(['id' => $therapistA->id, 'name' => 'Therapist In Spa A']);

        // Cross-tenant show -> 404
        Sanctum::actingAs($this->ownerB);
        $this->getJson("/api/therapists/{$therapistA->id}")->assertStatus(404);
        $this->patchJson("/api/therapists/{$therapistA->id}", ['name' => 'Hacked'])->assertStatus(404);
        $this->deleteJson("/api/therapists/{$therapistA->id}")->assertStatus(404);
    }

    // 4. Update: happy path, unique name ignoring self, role enforcement
    public function test_update_therapist_and_role_enforcement(): void
    {
        $therapist = Therapist::create([
            'spa_id' => $this->spaA->id,
            'name'   => 'Original Name',
            'color'  => '#3B82F6',
        ]);

        $other = Therapist::create([
            'spa_id' => $this->spaA->id,
            'name'   => 'Other Name',
            'color'  => '#10B981',
        ]);

        // Admin can update
        Sanctum::actingAs($this->adminA);

        // Keeping self name -> 200
        $updateSelf = $this->patchJson("/api/therapists/{$therapist->id}", [
            'name'  => 'original name',
            'notes' => 'Updated notes',
        ]);
        $updateSelf->assertStatus(200)
            ->assertJson([
                'message'   => 'Therapist updated successfully',
                'therapist' => ['id' => $therapist->id, 'notes' => 'Updated notes'],
            ]);

        // Renaming to existing other therapist's name -> 422
        $dupUpdate = $this->patchJson("/api/therapists/{$therapist->id}", [
            'name' => 'OTHER NAME',
        ]);
        $dupUpdate->assertStatus(422)->assertJsonValidationErrors(['name']);

        // Updating user_id to a user already linked to another therapist -> 422
        $linkedUser = User::create([
            'name'      => 'Linked User',
            'email'     => 'linked@alpha.test',
            'password'  => Hash::make('secret123'),
            'spa_id'    => $this->spaA->id,
            'role'      => 'staff',
            'is_active' => true,
        ]);
        $other->update(['user_id' => $linkedUser->id]);

        $updateConflictUser = $this->patchJson("/api/therapists/{$therapist->id}", [
            'user_id' => $linkedUser->id,
        ]);
        $updateConflictUser->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        // Staff cannot update -> 403
        Sanctum::actingAs($this->staffA);
        $this->patchJson("/api/therapists/{$therapist->id}", ['notes' => 'Hacked'])->assertStatus(403);
    }

    // 5. Delete: 409 when reservations exist, otherwise 200; role enforcement
    public function test_delete_therapist_blocked_by_reservations(): void
    {
        $therapist = Therapist::create([
            'spa_id' => $this->spaA->id,
            'name'   => 'Booked Therapist',
            'color'  => '#3B82F6',
        ]);

        $client = Client::create([
            'spa_id'    => $this->spaA->id,
            'full_name' => 'Client Test',
            'phone'     => '+212600000000',
        ]);

        $service = Service::create([
            'spa_id'           => $this->spaA->id,
            'name'             => 'Test Service',
            'duration_minutes' => 60,
            'price'            => 100,
            'is_active'        => true,
        ]);

        $reservation = Reservation::create([
            'spa_id'       => $this->spaA->id,
            'client_id'    => $client->id,
            'service_id'   => $service->id,
            'therapist_id' => $therapist->id,
            'start_time'   => now()->addDay(),
            'end_time'     => now()->addDay()->addHour(),
            'total_price'  => 100,
        ]);

        // Owner attempting delete -> 409
        Sanctum::actingAs($this->ownerA);
        $delBlock = $this->deleteJson("/api/therapists/{$therapist->id}");
        $delBlock->assertStatus(409)
            ->assertJson([
                'message' => "This therapist has 1 reservation(s) and can't be deleted. Deactivate them instead.",
            ]);

        // Staff attempting delete -> 403
        Sanctum::actingAs($this->staffA);
        $this->deleteJson("/api/therapists/{$therapist->id}")->assertStatus(403);

        // Remove reservation -> Admin can delete -> 200
        $reservation->delete();
        Sanctum::actingAs($this->adminA);
        $delSuccess = $this->deleteJson("/api/therapists/{$therapist->id}");
        $delSuccess->assertStatus(200)
            ->assertJson(['message' => 'Therapist deleted successfully']);

        $this->assertDatabaseMissing('therapists', ['id' => $therapist->id]);
    }
}
