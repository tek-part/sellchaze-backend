<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Models\UserSafetyRelation;
use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Community engagement v2: post/comment editing, archiving, follower graphs,
 * reaction lists, unified search and author roles.
 */
class CommunityEngagementV2Test extends TestCase
{
    use RefreshDatabase;

    private function user(string $email, array $overrides = []): User
    {
        return User::factory()->create(array_merge(['email' => $email, 'is_active' => true, 'pending_approval' => false], $overrides));
    }

    private function asUser(User $user): static
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    private function publicProfile(User $user, string $username): void
    {
        $user->profile()->create(['username' => $username, 'is_public' => true, 'company' => ucfirst($username).' Co']);
    }

    private function makePost(User $author, array $overrides = []): Post
    {
        return Post::query()->create(array_merge([
            'user_id' => $author->id,
            'type' => 'update_news',
            'body' => '<p>Hello community</p>',
            'status' => 'published',
            'published_at' => now(),
            'lifecycle_status' => 'published',
            'audience' => 'public',
        ], $overrides));
    }

    public function test_author_can_edit_post_and_hashtags_resync_and_edited_at_set(): void
    {
        $author = $this->user('edit-author@example.com');
        $post = $this->makePost($author, ['body' => '<p>Old #steel offer</p>']);
        $this->asUser($author)->postJson('/api/v1/posts', ['type' => 'update_news', 'body' => 'seed #steel'])->assertCreated();

        $response = $this->asUser($author)->patchJson("/api/v1/posts/{$post->id}", [
            'body' => '<p>New <strong>deal</strong> #packaging</p>',
            'audience' => 'followers',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.audience', 'followers');
        $this->assertNotNull($response->json('data.edited_at'));
        $this->assertTrue($post->refresh()->hashtags()->where('slug', 'packaging')->exists());
        $this->assertFalse($post->hashtags()->where('slug', 'steel')->exists());
    }

    public function test_non_author_cannot_edit_or_archive_post(): void
    {
        $author = $this->user('owner-a@example.com');
        $stranger = $this->user('stranger-a@example.com');
        $post = $this->makePost($author);

        $this->asUser($stranger)->patchJson("/api/v1/posts/{$post->id}", ['body' => 'hijack'])->assertForbidden();
        $this->asUser($stranger)->patchJson("/api/v1/posts/{$post->id}", ['lifecycle_status' => 'archived'])->assertForbidden();
        $this->assertSame('<p>Hello community</p>', $post->refresh()->body);
    }

    public function test_archived_post_hidden_everywhere_but_visible_to_author(): void
    {
        $author = $this->user('archive-author@example.com');
        $viewer = $this->user('archive-viewer@example.com');
        $post = $this->makePost($author, ['body' => '<p>Archive me</p>']);

        $this->asUser($author)->patchJson("/api/v1/posts/{$post->id}", ['lifecycle_status' => 'archived'])->assertOk();

        $feed = $this->asUser($viewer)->getJson('/api/v1/feed')->assertOk()->json('data');
        $this->assertNotContains($post->id, collect($feed)->pluck('id')->all());

        $this->asUser($viewer)->getJson("/api/v1/posts/{$post->id}")->assertNotFound();
        $this->asUser($author)->getJson("/api/v1/posts/{$post->id}")->assertOk();

        // Frozen: nobody (author included) comments on an archived post.
        $this->asUser($viewer)->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'hi'])->assertNotFound();
        $this->asUser($author)->postJson("/api/v1/posts/{$post->id}/comments", ['body' => 'hi'])->assertNotFound();
    }

    public function test_author_wall_archived_param_returns_only_archived_posts(): void
    {
        $author = $this->user('wall-author@example.com');
        $this->publicProfile($author, 'wall-author');
        $live = $this->makePost($author, ['body' => '<p>live</p>']);
        $archived = $this->makePost($author, ['body' => '<p>archived</p>', 'lifecycle_status' => 'archived']);

        $mine = $this->asUser($author)->getJson('/api/v1/feed?author=wall-author&archived=1')->assertOk()->json('data');
        $this->assertSame([$archived->id], collect($mine)->pluck('id')->all());

        // Someone else asking for the archive silently gets the published wall.
        $other = $this->user('wall-viewer@example.com');
        $their = $this->asUser($other)->getJson('/api/v1/feed?author=wall-author&archived=1')->assertOk()->json('data');
        $this->assertSame([$live->id], collect($their)->pluck('id')->all());
    }

    public function test_unarchive_restores_feed_visibility(): void
    {
        $author = $this->user('unarchive-author@example.com');
        $viewer = $this->user('unarchive-viewer@example.com');
        $post = $this->makePost($author, ['lifecycle_status' => 'archived']);

        // Prime the feed cache while the post is archived.
        $this->asUser($viewer)->getJson('/api/v1/feed')->assertOk();

        $this->asUser($author)->patchJson("/api/v1/posts/{$post->id}", ['lifecycle_status' => 'published'])->assertOk();

        $feed = $this->asUser($viewer)->getJson('/api/v1/feed')->assertOk()->json('data');
        $this->assertContains($post->id, collect($feed)->pluck('id')->all());
    }

    public function test_comment_author_can_edit_but_post_owner_can_only_delete(): void
    {
        $owner = $this->user('thread-owner@example.com');
        $commenter = $this->user('thread-commenter@example.com');
        $post = $this->makePost($owner);
        $comment = PostComment::query()->create(['post_id' => $post->id, 'user_id' => $commenter->id, 'body' => 'first take']);

        $this->asUser($commenter)
            ->patchJson("/api/v1/posts/{$post->id}/comments/{$comment->id}", ['body' => 'second take'])
            ->assertOk()
            ->assertJsonPath('data.body', 'second take')
            ->assertJsonPath('data.can_edit', true);
        $this->assertNotNull($comment->refresh()->edited_at);

        $this->asUser($owner)
            ->patchJson("/api/v1/posts/{$post->id}/comments/{$comment->id}", ['body' => 'rewrite'])
            ->assertForbidden();

        // The owner sees can_delete on someone else's comment (flag now matches destroy()).
        $list = $this->asUser($owner)->getJson("/api/v1/posts/{$post->id}/comments")->assertOk()->json('data');
        $this->assertTrue(collect($list)->firstWhere('id', $comment->id)['can_delete']);
    }

    public function test_follower_lists_paginate_with_flags_and_respect_blocks(): void
    {
        $target = $this->user('graph-target@example.com');
        $fan = $this->user('graph-fan@example.com');
        $mutual = $this->user('graph-mutual@example.com');
        $blocked = $this->user('graph-blocked@example.com');
        foreach ([[$fan, $target], [$mutual, $target], [$target, $mutual], [$blocked, $target]] as [$a, $b]) {
            $this->asUser($a)->postJson('/api/v1/follows', ['user_id' => $b->id])->assertCreated();
        }
        UserSafetyRelation::query()->create(['actor_user_id' => $mutual->id, 'target_user_id' => $blocked->id, 'type' => 'block']);

        $rows = $this->asUser($mutual)->getJson("/api/v1/users/{$target->id}/followers")->assertOk()->json('data');
        $ids = collect($rows)->pluck('id');
        $this->assertTrue($ids->contains($fan->id));
        $this->assertFalse($ids->contains($blocked->id), 'blocked members must not appear');

        // Viewed by the target: they follow mutual back, and every row follows them.
        $own = $this->asUser($target)->getJson("/api/v1/users/{$target->id}/followers")->assertOk()->json('data');
        $this->assertTrue(collect($own)->firstWhere('id', $mutual->id)['is_following']);
        $this->assertTrue(collect($own)->firstWhere('id', $fan->id)['follows_you']);

        // A fully blocked pair cannot browse each other's graphs at all.
        UserSafetyRelation::query()->create(['actor_user_id' => $target->id, 'target_user_id' => $blocked->id, 'type' => 'block']);
        $this->asUser($blocked)->getJson("/api/v1/users/{$target->id}/followers")->assertNotFound();
    }

    public function test_public_profile_returns_follow_counts_and_viewer_flags(): void
    {
        $target = $this->user('profile-target@example.com');
        $this->publicProfile($target, 'profile-target');
        $fan = $this->user('profile-fan@example.com');

        // Truly anonymous (before any token is attached to the test client).
        $this->getJson('/api/v1/public/profile/profile-target')
            ->assertOk()
            ->assertJsonPath('stats.followers_count', 0)
            ->assertJsonPath('viewer.is_following', false);

        $this->asUser($fan)->postJson('/api/v1/follows', ['user_id' => $target->id])->assertCreated();

        $this->asUser($fan)->getJson('/api/v1/public/profile/profile-target')
            ->assertOk()
            ->assertJsonPath('stats.followers_count', 1)
            ->assertJsonPath('viewer.is_following', true)
            ->assertJsonPath('viewer.is_self', false);
    }

    public function test_reactions_list_unions_likes_and_reactions_with_summary(): void
    {
        $author = $this->user('react-author@example.com');
        $liker = $this->user('react-liker@example.com');
        $clapper = $this->user('react-clapper@example.com');
        $post = $this->makePost($author);

        $this->asUser($liker)->postJson("/api/v1/posts/{$post->id}/like")->assertOk();
        $this->asUser($clapper)->postJson("/api/v1/posts/{$post->id}/reaction", ['type' => 'celebrate'])->assertOk();

        $all = $this->asUser($author)->getJson("/api/v1/posts/{$post->id}/reactions")->assertOk();
        $this->assertCount(2, $all->json('data'));
        $this->assertSame(1, $all->json('meta.summary.like'));
        $this->assertSame(1, $all->json('meta.summary.celebrate'));

        $filtered = $this->asUser($author)->getJson("/api/v1/posts/{$post->id}/reactions?type=celebrate")->assertOk()->json('data');
        $this->assertCount(1, $filtered);
        $this->assertSame('celebrate', $filtered[0]['type']);

        // Followers-only post: a stranger cannot list reactors.
        $hidden = $this->makePost($author, ['audience' => 'followers']);
        $this->asUser($liker)->getJson("/api/v1/posts/{$hidden->id}/reactions")->assertNotFound();
    }

    public function test_search_returns_sections_and_respects_visibility(): void
    {
        $author = $this->user('search-author@example.com');
        $this->publicProfile($author, 'search-author');
        $follower = $this->user('search-follower@example.com');
        $stranger = $this->user('search-stranger@example.com');
        $this->asUser($follower)->postJson('/api/v1/follows', ['user_id' => $author->id])->assertCreated();

        $this->makePost($author, ['body' => '<p>Exclusive granite pricing</p>', 'audience' => 'followers']);
        $this->makePost($author, ['body' => '<p>Public granite catalogue</p>']);

        $this->asUser($stranger)->getJson('/api/v1/search?q=g')->assertStatus(422);

        $strangerHits = $this->asUser($stranger)->getJson('/api/v1/search?q=granite&type=posts')->assertOk()->json('data');
        $this->assertCount(1, $strangerHits);

        $followerHits = $this->asUser($follower)->getJson('/api/v1/search?q=granite&type=posts')->assertOk()->json('data');
        $this->assertCount(2, $followerHits);

        $sections = $this->asUser($follower)->getJson('/api/v1/search?q=search-author')->assertOk()->json('data');
        $this->assertNotEmpty($sections['users']);
        $this->assertArrayHasKey('hashtags', $sections);
    }

    public function test_hashtag_page_returns_feed_shaped_visible_posts(): void
    {
        $author = $this->user('tag-author@example.com');
        $viewer = $this->user('tag-viewer@example.com');
        $this->asUser($author)->postJson('/api/v1/posts', ['type' => 'update_news', 'body' => 'Fresh #cement stock'])->assertCreated();

        $page = $this->asUser($viewer)->getJson('/api/v1/hashtags/cement/posts')->assertOk();
        $page->assertJsonPath('hashtag.slug', 'cement');
        $this->assertCount(1, $page->json('data'));

        $this->asUser($viewer)->getJson('/api/v1/hashtags/nope-missing/posts')->assertNotFound();
    }

    public function test_payloads_include_author_role(): void
    {
        Role::findOrCreate('Supplier', 'web');
        $supplier = $this->user('role-supplier@example.com');
        $supplier->assignRole('Supplier');
        $post = $this->makePost($supplier);
        PostComment::query()->create(['post_id' => $post->id, 'user_id' => $supplier->id, 'body' => 'my own note']);

        $viewer = $this->user('role-viewer@example.com');
        $this->asUser($viewer)->getJson("/api/v1/posts/{$post->id}")
            ->assertOk()->assertJsonPath('data.author.role', 'supplier');
        $comments = $this->asUser($viewer)->getJson("/api/v1/posts/{$post->id}/comments")->assertOk()->json('data');
        $this->assertSame('supplier', $comments[0]['author']['role']);
    }

    public function test_deactivated_account_gets_machine_readable_403(): void
    {
        $user = $this->user('deactivated@example.com');
        $token = JwtTokenService::fromConfig()->issueAccessToken($user);
        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_deactivated');
    }

    public function test_show_hydrates_saved_and_reaction_state(): void
    {
        $author = $this->user('parity-author@example.com');
        $viewer = $this->user('parity-viewer@example.com');
        $post = $this->makePost($author);

        $this->asUser($viewer)->postJson("/api/v1/posts/{$post->id}/save")->assertOk();
        $this->asUser($viewer)->postJson("/api/v1/posts/{$post->id}/reaction", ['type' => 'insightful'])->assertOk();

        $this->asUser($viewer)->getJson("/api/v1/posts/{$post->id}")
            ->assertOk()
            ->assertJsonPath('data.saved', true)
            ->assertJsonPath('data.reaction', 'insightful')
            ->assertJsonPath('data.reaction_summary.insightful', 1);
    }
}
