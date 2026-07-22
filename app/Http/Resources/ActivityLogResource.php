<?php

namespace App\Http\Resources;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityLog
 */
class ActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'event_type' => $this->event_type,
            'channel' => $this->channel,
            'level' => $this->level,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'properties' => $this->properties,
            'context' => $this->context,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ] : null),
        ];
    }
}
