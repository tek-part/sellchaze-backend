<?php

namespace App\Services\Themes;

use App\Models\Theme;
use App\Models\ThemeStatusChange;
use RuntimeException;

/**
 * Theme publishing workflow (Task 5): draft -> review -> approved -> published,
 * plus deprecate. Transitions are validated (no direct publish bypass) and audited.
 */
class ThemePublishingService
{
    /** Allowed transitions. */
    public const FLOW = [
        'draft' => ['review'],
        'review' => ['approved', 'draft'],
        'approved' => ['published', 'review'],
        'published' => ['deprecated'],
        'deprecated' => ['published'],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::FLOW[$from] ?? [], true);
    }

    public function transition(Theme $theme, string $to, ?int $actorId = null, ?string $notes = null): Theme
    {
        $from = (string) $theme->status;

        if (! in_array($to, Theme::STATUSES, true)) {
            throw new RuntimeException("Unknown status: {$to}");
        }
        if (! $this->canTransition($from, $to)) {
            throw new RuntimeException("Invalid transition: {$from} -> {$to}");
        }

        $theme->status = $to;
        $theme->save();

        ThemeStatusChange::create([
            'theme_id' => $theme->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actorId,
            'notes' => $notes,
        ]);

        return $theme;
    }
}
