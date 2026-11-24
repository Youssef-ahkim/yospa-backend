<?php

namespace Tests\Feature;

use App\Models\PlatformAuditLog;
use App\Models\Spa;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketTest extends TestCase
{
    use RefreshDatabase;

    private User $superuser;
    private Spa $spa1;
    private Spa $spa2;
    private User $owner1;
    private User $admin1;
    private User $staff1;
    private User $owner2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superuser = User::create([
            'name'      => 'Super Admin',
            'email'     => 'superuser@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => null,
            'role'      => 'superuser',
            'is_active' => true,
        ]);

        $this->spa1 = Spa::create([
            'name'   => 'First Spa Resort',
            'slug'   => 'first-spa-resort',
            'status' => 'active',
        ]);

        $this->spa2 = Spa::create([
            'name'   => 'Second Spa Oasis',
            'slug'   => 'second-spa-oasis',
            'status' => 'active',
        ]);

        $this->owner1 = User::create([
            'name'      => 'Spa 1 Owner',
            'email'     => 'owner1@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa1->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->admin1 = User::create([
            'name'      => 'Spa 1 Admin',
            'email'     => 'admin1@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa1->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $this->staff1 = User::create([
            'name'      => 'Spa 1 Staff',
            'email'     => 'staff1@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa1->id,
            'role'      => 'staff',
            'is_active' => true,
        ]);

        $this->owner2 = User::create([
            'name'      => 'Spa 2 Owner',
            'email'     => 'owner2@yospa.test',
            'password'  => Hash::make('password123'),
            'spa_id'    => $this->spa2->id,
            'role'      => 'owner',
            'is_active' => true,
        ]);
    }

    public function test_owner_can_list_and_filter_tickets_scoped_to_spa(): void
    {
        Sanctum::actingAs($this->owner1);

        $t1 = Ticket::create([
            'spa_id'             => $this->spa1->id,
            'created_by_user_id' => $this->owner1->id,
            'subject'            => 'Payment gateway error',
            'category'           => 'billing',
            'priority'           => 'high',
            'status'             => 'open',
            'description'        => 'Credit card charges are failing intermittently.',
        ]);

        $t2 = Ticket::create([
            'spa_id'             => $this->spa1->id,
            'created_by_user_id' => $this->admin1->id,
            'subject'            => 'Feature request for export',
            'category'           => 'feature',
            'priority'           => 'low',
            'status'             => 'closed',
            'description'        => 'We would love to export reports to CSV.',
        ]);

        // Spa 2 ticket (should never show up for owner1)
        Ticket::create([
            'spa_id'             => $this->spa2->id,
            'created_by_user_id' => $this->owner2->id,
            'subject'            => 'Spa 2 secret ticket',
            'category'           => 'bug',
            'priority'           => 'medium',
            'status'             => 'open',
            'description'        => 'Should not be seen by Spa 1',
        ]);

        $response = $this->getJson('/api/tickets');
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $t2->id)
            ->assertJsonPath('data.1.id', $t1->id)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'subject', 'category', 'priority', 'status', 'creator', 'messages_count'],
                ],
            ]);

        // Filter by status=open
        $openRes = $this->getJson('/api/tickets?status=open');
        $openRes->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $t1->id);

        // Filter by category=feature
        $featureRes = $this->getJson('/api/tickets?category=feature');
        $featureRes->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $t2->id);

        // Search by keyword
        $searchRes = $this->getJson('/api/tickets?search=Payment');
        $searchRes->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $t1->id);
    }

    public function test_admin_can_create_ticket_with_defaults(): void
    {
        Sanctum::actingAs($this->admin1);

        $payload = [
            'subject'     => 'Calendar glitch on Monday',
            'category'    => 'bug',
            'description' => 'Appointments shift by one hour on Monday view.',
        ];

        $response = $this->postJson('/api/tickets', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('message', 'Ticket created successfully')
            ->assertJsonPath('ticket.subject', 'Calendar glitch on Monday')
            ->assertJsonPath('ticket.category', 'bug')
            ->assertJsonPath('ticket.priority', 'medium')
            ->assertJsonPath('ticket.status', 'open')
            ->assertJsonPath('ticket.created_by_user_id', $this->admin1->id)
            ->assertJsonPath('ticket.spa_id', $this->spa1->id);

        $this->assertDatabaseHas('tickets', [
            'subject'     => 'Calendar glitch on Monday',
            'spa_id'      => $this->spa1->id,
            'status'      => 'open',
            'priority'    => 'medium',
        ]);
    }

    public function test_staff_role_is_forbidden_from_all_tenant_ticket_routes(): void
    {
        Sanctum::actingAs($this->staff1);

        $ticket = Ticket::create([
            'spa_id'             => $this->spa1->id,
            'created_by_user_id' => $this->owner1->id,
            'subject'            => 'Staff forbidden test',
            'category'           => 'bug',
            'priority'           => 'medium',
            'status'             => 'open',
            'description'        => 'Testing staff block',
        ]);

        $this->getJson('/api/tickets')->assertForbidden();
        $this->postJson('/api/tickets', [
            'subject'     => 'Staff ticket',
            'category'    => 'bug',
            'description' => 'Forbidden attempt',
        ])->assertForbidden();
        $this->getJson("/api/tickets/{$ticket->id}")->assertForbidden();
        $this->postJson("/api/tickets/{$ticket->id}/messages", ['body' => 'Hello'])->assertForbidden();
        $this->patchJson("/api/tickets/{$ticket->id}/close")->assertForbidden();
    }

    public function test_cross_spa_ticket_isolation_returns_404(): void
    {
        Sanctum::actingAs($this->owner1);

        $foreignTicket = Ticket::create([
            'spa_id'             => $this->spa2->id,
            'created_by_user_id' => $this->owner2->id,
            'subject'            => 'Spa 2 private ticket',
            'category'           => 'bug',
            'priority'           => 'medium',
            'status'             => 'open',
            'description'        => 'Should be completely inaccessible',
        ]);

        $this->getJson("/api/tickets/{$foreignTicket->id}")->assertNotFound();
        $this->postJson("/api/tickets/{$foreignTicket->id}/messages", ['body' => 'Trying to reply'])->assertNotFound();
        $this->patchJson("/api/tickets/{$foreignTicket->id}/close")->assertNotFound();
    }

    public function test_owner_can_reply_and_cannot_reply_when_closed(): void
    {
        Sanctum::actingAs($this->owner1);

        $ticket = Ticket::create([
            'spa_id'             => $this->spa1->id,
            'created_by_user_id' => $this->owner1->id,
            'subject'            => 'Need help with tax rates',
            'category'           => 'billing',
            'priority'           => 'medium',
            'status'             => 'open',
            'description'        => 'How do I configure VAT?',
        ]);

        $replyRes = $this->postJson("/api/tickets/{$ticket->id}/messages", [
            'body' => 'Here is additional info about our local tax rules.',
        ]);

        $replyRes->assertStatus(201)
            ->assertJsonPath('ticket_message.body', 'Here is additional info about our local tax rules.')
            ->assertJsonPath('ticket_message.is_platform_reply', false)
            ->assertJsonPath('ticket_message.user_id', $this->owner1->id);

        $closeRes = $this->patchJson("/api/tickets/{$ticket->id}/close");
        $closeRes->assertOk()
            ->assertJsonPath('ticket.status', 'closed');

        $this->assertDatabaseHas('tickets', [
            'id'     => $ticket->id,
            'status' => 'closed',
        ]);

        // Attempting to reply to a closed ticket must yield 422
        $failRes = $this->postJson("/api/tickets/{$ticket->id}/messages", [
            'body' => 'This reply should fail because ticket is closed.',
        ]);
        $failRes->assertStatus(422)
            ->assertJsonPath('message', 'Cannot reply to a closed ticket.');
    }

    public function test_platform_superuser_can_list_all_tickets_across_spas(): void
    {
        Sanctum::actingAs($this->superuser);

        $t1 = Ticket::create([
            'spa_id'             => $this->spa1->id,
            'created_by_user_id' => $this->owner1->id,
            'subject'            => 'Spa 1 issue',
            'category'           => 'bug',
            'priority'           => 'high',
            'status'             => 'open',
            'description'        => 'First spa issue description',
        ]);

        $t2 = Ticket::create([
            'spa_id'             => $this->spa2->id,
            'created_by_user_id' => $this->owner2->id,
            'subject'            => 'Spa 2 question',
            'category'           => 'billing',
            'priority'           => 'low',
            'status'             => 'resolved',
            'description'        => 'Second spa question description',
        ]);

        $res = $this->getJson('/api/platform/tickets');
        $res->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'subject', 'category', 'priority', 'status', 'spa', 'creator', 'messages_count'],
                ],
            ]);

        // Filter by spa_id
        $spa1Res = $this->getJson("/api/platform/tickets?spa_id={$this->spa1->id}");
        $spa1Res->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $t1->id);

        // Search by spa name
        $searchRes = $this->getJson('/api/platform/tickets?search=Second');
        $searchRes->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $t2->id);
    }

    public function test_platform_superuser_can_view_ticket_detail(): void
    {
        Sanctum::actingAs($this->superuser);

        $ticket = Ticket::create([
            'spa_id'             => $this->spa1->id,
            'created_by_user_id' => $this->owner1->id,
            'subject'            => 'Superuser detail inspection',
            'category'           => 'other',
            'priority'           => 'medium',
            'status'             => 'open',
            'description'        => 'Inspection test body',
        ]);

        TicketMessage::create([
            'ticket_id'         => $ticket->id,
            'user_id'           => $this->owner1->id,
            'body'              => 'First initial follow-up',
            'is_platform_reply' => false,
        ]);

        $res = $this->getJson("/api/platform/tickets/{$ticket->id}");
        $res->assertOk()
            ->assertJsonPath('id', $ticket->id)
            ->assertJsonPath('spa.id', $this->spa1->id)
            ->assertJsonPath('creator.id', $this->owner1->id)
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'First initial follow-up');
    }

    public function test_platform_superuser_can_update_status_and_priority_with_audit_log(): void
    {
        Sanctum::actingAs($this->superuser);

        $ticket = Ticket::create([
            'spa_id'             => $this->spa1->id,
            'created_by_user_id' => $this->owner1->id,
            'subject'            => 'Status update test',
            'category'           => 'bug',
            'priority'           => 'low',
            'status'             => 'open',
            'description'        => 'Test description',
        ]);

        // Resolve ticket
        $updateRes = $this->patchJson("/api/platform/tickets/{$ticket->id}", [
            'status'   => 'resolved',
            'priority' => 'high',
        ]);

        $updateRes->assertOk()
            ->assertJsonPath('ticket.status', 'resolved')
            ->assertJsonPath('ticket.priority', 'high');

        $ticket->refresh();
        $this->assertEquals('resolved', $ticket->status);
        $this->assertEquals('high', $ticket->priority);
        $this->assertNotNull($ticket->resolved_at);

        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_user_id' => $this->superuser->id,
            'action'        => 'ticket.updated',
            'subject_type'  => 'Ticket',
            'subject_id'    => $ticket->id,
            'spa_id'        => $this->spa1->id,
        ]);

        // Reopening ticket clears resolved_at
        $reopenRes = $this->patchJson("/api/platform/tickets/{$ticket->id}", [
            'status' => 'in_progress',
        ]);
        $reopenRes->assertOk();
        $ticket->refresh();
        $this->assertEquals('in_progress', $ticket->status);
        $this->assertNull($ticket->resolved_at);
    }

    public function test_platform_superuser_reply_sets_status_to_in_progress_and_creates_audit_log(): void
    {
        Sanctum::actingAs($this->superuser);

        $ticket = Ticket::create([
            'spa_id'             => $this->spa1->id,
            'created_by_user_id' => $this->owner1->id,
            'subject'            => 'Reply transition test',
            'category'           => 'bug',
            'priority'           => 'medium',
            'status'             => 'open',
            'description'        => 'Open ticket waiting for reply',
        ]);

        $res = $this->postJson("/api/platform/tickets/{$ticket->id}/messages", [
            'body' => 'We are investigating this issue on our infrastructure.',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('ticket_message.body', 'We are investigating this issue on our infrastructure.')
            ->assertJsonPath('ticket_message.is_platform_reply', true)
            ->assertJsonPath('ticket.status', 'in_progress');

        $ticket->refresh();
        $this->assertEquals('in_progress', $ticket->status);

        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_user_id' => $this->superuser->id,
            'action'        => 'ticket.replied',
            'subject_type'  => 'Ticket',
            'subject_id'    => $ticket->id,
            'spa_id'        => $this->spa1->id,
        ]);
    }

    public function test_tenant_cannot_access_platform_tickets_and_superuser_cannot_access_tenant_tickets(): void
    {
        // Owner trying platform endpoint
        Sanctum::actingAs($this->owner1);
        $this->getJson('/api/platform/tickets')->assertForbidden();

        // Superuser trying tenant endpoint (no spa_id -> tenant middleware 403)
        Sanctum::actingAs($this->superuser);
        $this->getJson('/api/tickets')->assertForbidden();
    }
}
