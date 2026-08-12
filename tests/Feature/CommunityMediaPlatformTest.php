<?php

namespace Tests\Feature;

use App\Jobs\ProcessCommunityMedia;
use App\Models\Post;
use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommunityMediaPlatformTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        return User::factory()->create(['email' => $email, 'is_active' => true, 'pending_approval' => false]);
    }

    private function asUser(User $user): static
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    public function test_chunked_upload_can_resume_complete_and_attach_to_post(): void
    {
        Storage::fake('local'); Storage::fake('public'); Queue::fake();
        config(['community.chunk_size' => 16]);
        $user = $this->user('uploader@example.com');
        $file = UploadedFile::fake()->image('catalog.png', 20, 20);
        $bytes = file_get_contents($file->getRealPath());

        $session = $this->asUser($user)->postJson('/api/v1/community/media/uploads', [
            'name' => 'catalog.png', 'size_bytes' => strlen($bytes), 'mime' => 'image/png', 'checksum_sha256' => hash('sha256', $bytes),
        ])->assertCreated()->json('data');

        foreach (str_split($bytes, 16) as $index => $chunk) {
            $this->asUser($user)->post('/api/v1/community/media/uploads/'.$session['upload_id'].'/parts/'.($index + 1), [
                'chunk' => UploadedFile::fake()->createWithContent('part.bin', $chunk), 'checksum_sha256' => hash('sha256', $chunk),
            ], ['Accept' => 'application/json'])->assertOk();
        }

        $this->asUser($user)->getJson('/api/v1/community/media/uploads/'.$session['upload_id'])
            ->assertOk()->assertJsonCount($session['total_chunks'], 'data.uploaded_parts');
        $asset = $this->asUser($user)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/community/media/uploads/'.$session['upload_id'].'/complete')->assertOk()->json('data');
        Queue::assertPushed(ProcessCommunityMedia::class);

        $this->asUser($user)->postJson('/api/v1/posts', [
            'type' => 'new_product', 'body' => 'New catalog #wholesale', 'media_asset_ids' => [$asset['id']], 'cta_type' => 'request_quote', 'cta_label' => 'Request quote',
        ])->assertCreated()->assertJsonPath('data.media.0.id', $asset['id'])->assertJsonPath('data.cta.type', 'request_quote');
        $this->assertDatabaseHas('hashtags', ['slug' => 'wholesale', 'posts_count' => 1]);
    }

    public function test_groups_reels_events_and_audience_rules_work_together(): void
    {
        $owner = $this->user('group-owner@example.com'); $member = $this->user('group-member@example.com'); $outsider = $this->user('group-outsider@example.com');
        $group = $this->asUser($owner)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/community/groups', [
            'name' => 'Packaging Exporters', 'description' => 'Export opportunities', 'privacy' => 'private',
        ])->assertCreated()->json('data');
        $this->asUser($member)->postJson('/api/v1/community/groups/'.$group['id'].'/join')->assertOk()->assertJsonPath('status', 'pending');
        $this->asUser($outsider)->getJson('/api/v1/community/groups/'.$group['id'])->assertForbidden();

        $postId = $this->asUser($owner)->postJson('/api/v1/posts', [
            'type' => 'question', 'body' => 'Private sourcing request', 'community_group_id' => $group['id'], 'comments_enabled' => false,
        ])->assertCreated()->json('data.id');
        $this->asUser($outsider)->getJson('/api/v1/posts/'.$postId)->assertNotFound();
        $this->asUser($owner)->postJson('/api/v1/posts/'.$postId.'/comments', ['body' => 'No comments'])->assertStatus(422);

        $eventUuid = (string) Str::uuid();
        $this->asUser($owner)->postJson('/api/v1/feed/events', ['events' => [[
            'event_uuid' => $eventUuid, 'post_id' => $postId, 'event_type' => 'impression', 'occurred_at' => now()->toIso8601String(), 'session_id' => 'test-session',
        ]]])->assertOk();
        $this->assertDatabaseHas('feed_events', ['event_uuid' => $eventUuid, 'event_type' => 'impression']);
        $this->assertSame(1, Post::query()->findOrFail($postId)->communityGroup->posts_count);
        $this->artisan('community:maintain')->assertExitCode(0);
    }
}
