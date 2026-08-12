<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BusinessSocialNetworkTest extends TestCase
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

    private function organization(User $owner, string $slug): Organization
    {
        $organization = Organization::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $organization->memberships()->create([
            'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        return $organization;
    }

    public function test_company_profile_and_company_identity_post_are_publicly_discoverable(): void
    {
        $owner = $this->user('social-owner@example.com');
        $organization = $this->organization($owner, 'precision-factory');

        $this->asUser($owner)->patchJson("/api/v2/organizations/{$organization->id}", [
            'headline' => 'Precision packaging manufacturer',
            'about' => 'Export-ready factory.',
            'website' => 'https://factory.example.com',
            'locations' => [['label' => 'Cairo plant', 'country_code' => 'EG']],
            'capabilities' => ['Injection molding', 'Private label'],
            'certificates' => [['name' => 'ISO 9001', 'url' => 'https://factory.example.com/iso.pdf']],
        ])->assertOk()->assertJsonPath('data.profile.headline', 'Precision packaging manufacturer');

        $post = $this->asUser($owner)->postJson('/api/v1/posts', [
            'type' => 'update_news',
            'body' => 'New production line available #packaging @buyers',
            'acting_organization_id' => $organization->id,
            'attachments' => ['https://cdn.example.com/capability.pdf'],
            'meta' => ['tags' => ['packaging'], 'mentions' => ['buyers']],
        ])->assertCreated()
            ->assertJsonPath('data.acting_as.id', $organization->id)
            ->assertJsonPath('data.acting_as.type', 'organization');

        $this->asUser($this->user('social-viewer@example.com'))
            ->getJson('/api/v2/directory/organizations/precision-factory')
            ->assertOk()->assertJsonPath('data.capabilities.0', 'Injection molding');
        $this->assertDatabaseHas('posts', ['id' => $post->json('data.id'), 'organization_id' => $organization->id]);
    }

    public function test_employee_needs_explicit_permission_to_publish_as_company(): void
    {
        $owner = $this->user('permission-owner@example.com');
        $employee = $this->user('permission-employee@example.com');
        $organization = $this->organization($owner, 'permission-company');
        $membership = $organization->memberships()->create([
            'user_id' => $employee->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);
        $payload = ['type' => 'update_news', 'body' => 'Company announcement', 'acting_organization_id' => $organization->id];

        $this->asUser($employee)->postJson('/api/v1/posts', $payload)->assertForbidden();
        $membership->update(['permissions' => ['post_as_company']]);
        $this->asUser($employee)->postJson('/api/v1/posts', $payload)->assertCreated();
    }

    public function test_save_cursor_feed_and_blocking_are_enforced(): void
    {
        $author = $this->user('feed-author@example.com');
        $viewer = $this->user('feed-viewer@example.com');
        $postId = $this->asUser($author)->postJson('/api/v1/posts', [
            'type' => 'question', 'body' => 'Who can supply recycled cartons?',
        ])->assertCreated()->json('data.id');

        $this->asUser($viewer)->postJson("/api/v1/posts/{$postId}/save")
            ->assertOk()->assertJsonPath('saved', true);
        $this->asUser($viewer)->getJson('/api/v1/feed?cursor=&per_page=5')
            ->assertOk()->assertJsonPath('data.0.saved', true)
            ->assertJsonStructure(['meta' => ['per_page', 'next_cursor', 'previous_cursor']]);

        $this->asUser($viewer)->postJson("/api/v1/users/{$author->id}/block")->assertOk();
        $this->asUser($viewer)->getJson('/api/v1/feed')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($author)->postJson('/api/v1/chat/conversations', ['user_id' => $viewer->id])
            ->assertForbidden()->assertJsonPath('message', 'Conversation is unavailable.');
        $this->asUser($viewer)->deleteJson("/api/v1/users/{$author->id}/block")->assertOk();
        $this->asUser($viewer)->getJson('/api/v1/feed')->assertOk()->assertJsonCount(1, 'data');

        $organization = $this->organization($author, 'followed-company');
        $this->asUser($viewer)->postJson("/api/v1/organizations/{$organization->id}/follow")
            ->assertOk()->assertJsonPath('following', true);
        $this->asUser($viewer)->getJson('/api/v2/directory/organizations/followed-company')
            ->assertOk()->assertJsonPath('data.following', true)->assertJsonPath('data.followers_count', 1);
    }

    public function test_report_and_admin_moderation_action_hide_content_with_an_audit_trail(): void
    {
        $author = $this->user('reported-author@example.com');
        $reporter = $this->user('reporter@example.com');
        $admin = $this->user('moderator@example.com');
        Role::findOrCreate('Admin');
        $admin->assignRole('Admin');
        $postId = $this->asUser($author)->postJson('/api/v1/posts', [
            'type' => 'ad_offer', 'body' => 'Suspicious repeated offer',
        ])->assertCreated()->json('data.id');

        $reportId = $this->asUser($reporter)->postJson('/api/v1/reports', [
            'target_type' => 'post', 'target_id' => $postId, 'reason' => 'spam', 'details' => 'Repeated solicitation.',
        ])->assertCreated()->json('data.id');

        $this->asUser($admin)->postJson("/api/v1/admin/moderation/reports/{$reportId}/review", [
            'action' => 'hide_content', 'notes' => 'Confirmed by moderator.',
        ])->assertOk()->assertJsonPath('data.status', 'actioned');

        $this->assertSame('hidden', Post::query()->findOrFail($postId)->status);
        $this->assertDatabaseHas('moderation_actions', [
            'content_report_id' => $reportId, 'moderator_user_id' => $admin->id, 'action' => 'hide_content',
        ]);
        $organization = $this->organization($author, 'verified-social-company');
        $this->asUser($admin)->postJson("/api/v1/admin/organizations/{$organization->id}/verification", [
            'verified' => true, 'reason' => 'Registration and certificate review passed.',
        ])->assertOk()->assertJsonPath('data.is_verified', true);
        $this->assertDatabaseHas('organization_verification_events', [
            'organization_id' => $organization->id, 'moderator_user_id' => $admin->id, 'verified' => true,
        ]);
        $this->asUser($reporter)->getJson('/api/v1/feed')->assertOk()->assertJsonCount(0, 'data');
    }
}
