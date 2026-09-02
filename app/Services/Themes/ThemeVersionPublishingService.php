<?php

namespace App\Services\Themes;

use App\Models\ThemeVersion;
use App\Models\ThemeVersionStatusChange;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ThemeVersionPublishingService
{
    public const FLOW = [
        'draft' => ['review'],
        'review' => ['approved', 'draft'],
        'approved' => ['published', 'review'],
        'published' => ['deprecated'],
        'deprecated' => ['published'],
    ];

    public function transition(ThemeVersion $version, string $to, ?int $actorId, ?string $notes = null): ThemeVersion
    {
        $from = (string) $version->status;
        if (! in_array($to, self::FLOW[$from] ?? [], true)) {
            throw new RuntimeException("Invalid version transition: {$from} -> {$to}");
        }

        return DB::transaction(function () use ($version, $from, $to, $actorId, $notes): ThemeVersion {
            $version->forceFill([
                'status' => $to,
                'reviewed_by_user_id' => in_array($to, ['approved', 'published'], true) ? $actorId : $version->reviewed_by_user_id,
                'published_at' => $to === 'published' ? ($version->published_at ?? now()) : $version->published_at,
            ])->save();

            ThemeVersionStatusChange::create([
                'theme_version_id' => $version->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actorId,
                'notes' => $notes,
            ]);

            if (in_array($to, ['published', 'deprecated'], true)) {
                $latest = ThemeVersion::query()
                    ->where('theme_id', $version->theme_id)
                    ->where('status', 'published')
                    ->get()
                    ->sort(fn ($a, $b) => version_compare($a->version, $b->version))
                    ->last();
                $version->theme()->update(['latest_version_id' => $latest?->id]);
            }

            return $version->fresh();
        });
    }
}
