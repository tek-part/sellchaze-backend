<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OutboxMessage;
use App\Models\ProcurementAuditEntry;
use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalMaturityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, Organization} */
    private function company(string $slug): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $organization = Organization::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $organization->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $organization];
    }

    private function token(User $user): string
    {
        return JwtTokenService::fromConfig()->issueAccessToken($user);
    }

    public function test_organization_owner_can_export_only_its_procurement_audit(): void
    {
        [$buyer, $buyerOrganization] = $this->company('audit-buyer');
        [$other, $otherOrganization] = $this->company('audit-other');

        $requestId = $this->withToken($this->token($buyer))->postJson('/api/v2/procurement/requests', [
            'organization_id' => $buyerOrganization->id,
            'title' => 'Auditable packaging tender',
            'quantity' => 50,
            'unit' => 'box',
            'status' => 'published',
        ])->assertCreated()->json('data.id');

        $response = $this->withToken($this->token($buyer))
            ->get('/api/v2/organizations/'.$buyerOrganization->id.'/audit-export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('rfq_published', $csv);
        $this->assertStringContainsString($requestId, $csv);
        $this->withToken($this->token($other))
            ->get('/api/v2/organizations/'.$buyerOrganization->id.'/audit-export')
            ->assertForbidden();
        $this->withToken($this->token($other))
            ->get('/api/v2/organizations/'.$otherOrganization->id.'/audit-export')
            ->assertOk();
    }

    public function test_retention_supports_dry_run_and_deletes_only_expired_records(): void
    {
        config()->set('operations.retention.published_outbox_days', 30);
        config()->set('operations.retention.audit_logs_days', 365);
        [$buyer, $organization] = $this->company('retention-buyer');
        $requestId = $this->withToken($this->token($buyer))->postJson('/api/v2/procurement/requests', [
            'organization_id' => $organization->id,
            'title' => 'Retention tender',
            'quantity' => 1,
            'unit' => 'unit',
            'status' => 'published',
        ])->assertCreated()->json('data.id');

        $oldAudit = ProcurementAuditEntry::query()->where('procurement_request_id', $requestId)->firstOrFail();
        $oldAudit->timestamps = false;
        $oldAudit->forceFill(['created_at' => now()->subDays(366), 'updated_at' => now()->subDays(366)])->save();
        ProcurementAuditEntry::query()->create([
            'procurement_request_id' => $requestId,
            'actor_user_id' => $buyer->id,
            'event' => 'recent_audit',
        ]);

        $oldOutbox = OutboxMessage::query()->create([
            'id' => (string) Str::uuid(), 'aggregate_type' => 'test', 'aggregate_id' => '1',
            'event_type' => 'OldPublished', 'payload' => [], 'metadata' => [],
            'available_at' => now()->subDays(40), 'published_at' => now()->subDays(31),
        ]);
        $newOutbox = OutboxMessage::query()->create([
            'id' => (string) Str::uuid(), 'aggregate_type' => 'test', 'aggregate_id' => '2',
            'event_type' => 'RecentPublished', 'payload' => [], 'metadata' => [],
            'available_at' => now(), 'published_at' => now()->subDays(29),
        ]);

        $this->artisan('operations:retention --pretend')
            ->expectsOutputToContain('published_outbox: 1 eligible')
            ->expectsOutputToContain('procurement_audit: 1 eligible')
            ->assertSuccessful();
        $this->assertModelExists($oldAudit);
        $this->assertModelExists($oldOutbox);

        $this->artisan('operations:retention')->assertSuccessful();
        $this->assertModelMissing($oldAudit);
        $this->assertModelMissing($oldOutbox);
        $this->assertModelExists($newOutbox);
        $this->assertDatabaseHas('procurement_audit_entries', ['event' => 'recent_audit']);
    }
}
