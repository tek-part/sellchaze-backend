<?php

namespace App\Console\Commands;

use App\Models\Hashtag;
use App\Models\MediaUpload;
use App\Models\Post;
use App\Services\FeedCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MaintainCommunityPlatform extends Command
{
    protected $signature = 'community:maintain';
    protected $description = 'Aggregate community ranking signals, publish scheduled posts, and prune expired uploads';

    public function handle(FeedCache $cache): int
    {
        $published = Post::query()->where('lifecycle_status', 'scheduled')->where('scheduled_at', '<=', now())->update([
            'status' => 'published', 'lifecycle_status' => 'published', 'published_at' => now(),
        ]);

        $ranked = 0;
        Post::query()->published()->where('published_at', '>=', now()->subDays(45))->select(['id', 'likes_count', 'comments_count', 'shares_count', 'published_at'])->chunkById(250, function ($posts) use (&$ranked) {
            $events = DB::table('feed_events')->whereIn('post_id', $posts->pluck('id'))->where('occurred_at', '>=', now()->subDays(30))
                ->selectRaw("post_id, SUM(CASE WHEN event_type IN ('view_2s','reel_complete') THEN 1 ELSE 0 END) as views, SUM(CASE WHEN event_type IN ('product_view','rfq_start') THEN 1 ELSE 0 END) as intent, COALESCE(SUM(CASE WHEN event_type = 'dwell' THEN value_ms ELSE 0 END),0) as dwell")
                ->groupBy('post_id')->get()->keyBy('post_id');
            foreach ($posts as $post) {
                $signal = $events->get($post->id); $ageHours = max(1, $post->published_at?->diffInHours(now()) ?? 1);
                $quality = ($post->likes_count * .8) + ($post->comments_count * 1.8) + ($post->shares_count * 2.4) + (($signal->views ?? 0) * .12) + (($signal->intent ?? 0) * 4) + min(5, (($signal->dwell ?? 0) / 60000));
                $post->updateQuietly(['ranking_score' => round($quality / pow($ageHours + 2, .42), 4)]); $ranked++;
            }
        });

        Hashtag::query()->chunkById(200, function ($tags) {
            foreach ($tags as $tag) {
                $recent = $tag->posts()->published()->where('published_at', '>=', now()->subDays(7))->count();
                $tag->update(['posts_count' => $tag->posts()->published()->count(), 'trend_score' => $recent * 3]);
            }
        });

        $pruned = 0;
        MediaUpload::query()->whereIn('status', ['initiated', 'uploading'])->where('expires_at', '<', now())->with('asset')->chunkById(100, function ($uploads) use (&$pruned) {
            foreach ($uploads as $upload) {
                Storage::disk(config('community.chunk_disk'))->deleteDirectory('community-chunks/'.$upload->id);
                $upload->asset?->delete(); $pruned++;
            }
        });
        if ($published || $ranked) $cache->flush();
        $this->info("published={$published} ranked={$ranked} pruned_uploads={$pruned}");
        return self::SUCCESS;
    }
}

