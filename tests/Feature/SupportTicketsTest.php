<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketNotification;
use App\Services\JwtTokenService;
use App\Services\NotificationChannelResolver;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupportTicketsTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->seed(RolesTableSeeder::class);
        $this->merchant = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->merchant->assignRole('Merchant');
        Store::create(['owner_user_id' => $this->merchant->id, 'owner_type' => 'merchant', 'name' => 'Support store', 'slug' => 'support-store', 'status' => 'active']);
        $this->agent = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->agent->assignRole('Admin');
    }

    private function as(User $user): static
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    private function openTicket(): int
    {
        return $this->as($this->merchant)->post('/api/v1/support/tickets', [
            'subject' => 'Domain not connecting',
            'category' => 'domain',
            'body' => 'My custom domain still shows the old site after two days.',
            'attachments' => [UploadedFile::fake()->image('dns.png')],
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.store.slug', 'support-store')
            ->assertJsonPath('data.messages.0.attachments.0.name', 'dns.png')
            ->json('data.id');
    }

    public function test_merchant_opens_a_ticket_and_support_is_notified(): void
    {
        $id = $this->openTicket();

        Notification::assertSentTo($this->agent, SupportTicketNotification::class);
        $this->as($this->merchant)->getJson('/api/v1/support/tickets')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.owner', null);
        $this->as($this->agent)->getJson('/api/v1/admin/support/tickets?status=active')->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.unread', true)->assertJsonPath('data.0.owner.id', $this->merchant->id);
    }

    public function test_staff_reply_flips_status_assigns_agent_and_notifies_merchant(): void
    {
        $id = $this->openTicket();

        $this->as($this->agent)->postJson("/api/v1/admin/support/tickets/{$id}/messages", ['body' => 'Please check the CNAME record.'])
            ->assertCreated()->assertJsonPath('data.status', 'awaiting_merchant')->assertJsonPath('data.assignee.id', $this->agent->id);
        Notification::assertSentTo($this->merchant, SupportTicketNotification::class);

        $this->as($this->merchant)->getJson('/api/v1/support/tickets')->assertJsonPath('data.0.unread', true);
        $this->as($this->merchant)->getJson("/api/v1/support/tickets/{$id}")->assertOk()->assertJsonCount(2, 'data.messages');
        $this->as($this->merchant)->getJson('/api/v1/support/tickets')->assertJsonPath('data.0.unread', false);

        $this->as($this->agent)->patchJson("/api/v1/admin/support/tickets/{$id}", ['status' => 'resolved'])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->as($this->merchant)->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => 'Still broken.'])->assertCreated()->assertJsonPath('data.status', 'open');
        $this->as($this->merchant)->postJson("/api/v1/support/tickets/{$id}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->as($this->merchant)->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => 'Another note'])->assertUnprocessable();
    }

    public function test_tickets_and_attachments_are_private_to_their_merchant(): void
    {
        $id = $this->openTicket();
        $ticket = SupportTicket::findOrFail($id);
        $messageId = $ticket->messages()->value('id');
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');

        $this->as($other)->getJson("/api/v1/support/tickets/{$id}")->assertNotFound();
        $this->as($other)->get("/api/v1/support/tickets/{$id}/messages/{$messageId}/attachments/0")->assertNotFound();
        $this->as($other)->getJson('/api/v1/admin/support/tickets')->assertForbidden();
        $this->as($this->merchant)->get("/api/v1/support/tickets/{$id}/messages/{$messageId}/attachments/0")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->as($this->agent)->get("/api/v1/admin/support/tickets/{$id}/messages/{$messageId}/attachments/0")->assertOk();
    }

    public function test_validation_rejects_unsafe_attachments_and_bad_input(): void
    {
        $this->as($this->merchant)->post('/api/v1/support/tickets', [
            'subject' => 'x', 'category' => 'unknown', 'body' => 'short',
            'attachments' => [UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')],
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['subject', 'category', 'body', 'attachments.0']);
        $this->as($this->merchant)->postJson('/api/v1/support/tickets', ['subject' => 'Billing question', 'category' => 'billing', 'priority' => 'urgent', 'body' => 'How do order fees work exactly?'])
            ->assertUnprocessable()->assertJsonValidationErrors('priority');
    }

    public function test_agent_assignment_is_limited_to_support_staff(): void
    {
        $id = $this->openTicket();
        $this->as($this->agent)->patchJson("/api/v1/admin/support/tickets/{$id}", ['assigned_to_user_id' => $this->merchant->id])->assertUnprocessable();
        $this->as($this->agent)->patchJson("/api/v1/admin/support/tickets/{$id}", ['assigned_to_user_id' => $this->agent->id, 'priority' => 'high'])
            ->assertOk()->assertJsonPath('data.assignee.id', $this->agent->id)->assertJsonPath('data.priority', 'high');
    }

    public function test_a_notification_failure_does_not_lose_the_ticket(): void
    {
        $this->mock(NotificationChannelResolver::class)->shouldReceive('channels')->andThrow(new \RuntimeException('mail down'));
        Notification::swap(app(ChannelManager::class));

        $this->as($this->merchant)->postJson('/api/v1/support/tickets', ['subject' => 'Still here', 'category' => 'general', 'body' => 'This ticket must survive a mail outage.'])
            ->assertCreated();
        $this->assertDatabaseHas('support_tickets', ['subject' => 'Still here']);
    }
}
