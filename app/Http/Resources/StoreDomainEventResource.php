<?php

namespace App\Http\Resources;

use App\Models\StoreDomainEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoreDomainEvent
 */
class StoreDomainEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'host' => $this->host,
            'domain_id' => $this->store_domain_id,
            'actor' => [
                'type' => $this->actor_type,
                'user_id' => $this->actor_user_id,
                'name' => $this->whenLoaded('actor', function (): ?string {
                    $actor = $this->resource->actor;

                    return $actor instanceof User ? $actor->name : null;
                }),
            ],
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'reason' => $this->reason,
            'created_at' => $this->created_at,
        ];
    }
}
