<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\OutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalMaturityTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_supports_dry_run_and_deletes_only_expired_records(): void
    {
        config()->set('operations.retention.published_outbox_days', 30);
        config()->set('operations.retention.audit_logs_days', 365);

        $oldLog = (new ActivityLog)->forceFill(['action' => 'old_audit', 'level' => 'info', 'created_at' => now()->subDays(366)]);
        $oldLog->save();
        $recentLog = (new ActivityLog)->forceFill(['action' => 'recent_audit', 'level' => 'info', 'created_at' => now()]);
        $recentLog->save();

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
            ->expectsOutputToContain('activity_logs: 1 eligible')
            ->assertSuccessful();
        $this->assertModelExists($oldLog);
        $this->assertModelExists($oldOutbox);

        $this->artisan('operations:retention')->assertSuccessful();
        $this->assertModelMissing($oldLog);
        $this->assertModelMissing($oldOutbox);
        $this->assertModelExists($newOutbox);
        $this->assertModelExists($recentLog);
    }
}
