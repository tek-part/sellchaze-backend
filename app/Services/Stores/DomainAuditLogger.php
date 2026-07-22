<?php

namespace App\Services\Stores;

use App\Models\StoreDomain;
use App\Models\StoreDomainEvent;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The single writer of the domain audit trail.
 *
 * Every domain mutation funnels through here so the log can never drift from
 * reality, and so actor/IP/user-agent capture lives in exactly one place.
 *
 * Actor resolution: an explicit user wins; otherwise the authenticated user is
 * used; otherwise the event is attributed to `system` (scheduler/queue).
 */
class DomainAuditLogger
{
    public function record(
        StoreDomain $domain,
        string $event,
        ?User $actor = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $reason = null,
    ): StoreDomainEvent {
        $request = request();
        $actor ??= $this->currentUser();

        return StoreDomainEvent::create([
            'store_id' => $domain->store_id,
            'store_domain_id' => $domain->id,
            // Denormalised so history survives the domain being deleted.
            'host' => $domain->host,
            'event' => $event,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor !== null ? 'user' : 'system',
            'ip' => $request?->ip(),
            'user_agent' => Str::limit((string) $request?->userAgent(), 490) ?: null,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'reason' => $reason === null ? null : Str::limit($reason, 490),
        ]);
    }

    /**
     * Convenience for status transitions — the most common shape by far.
     */
    public function recordStatusChange(
        StoreDomain $domain,
        string $event,
        ?string $from,
        ?string $to,
        ?User $actor = null,
        ?string $reason = null,
    ): StoreDomainEvent {
        return $this->record(
            $domain,
            $event,
            $actor,
            $from === null ? null : ['status' => $from],
            $to === null ? null : ['status' => $to],
            $reason,
        );
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
