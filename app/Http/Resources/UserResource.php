<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    private function isoDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $avatarUrl = null;
        $profile = $this->relationLoaded('profile') ? $this->profile : null;
        if ($profile && ! empty($profile->photo)) {
            $avatarUrl = asset('storage/uploads/users/thumbnails/'.$profile->photo);
        } elseif (! empty($this->avatar)) {
            $avatarUrl = $this->avatar;
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'google_connected' => ! empty($this->google_id),
            'google_email' => ! empty($this->google_id) ? $this->email : null,
            'avatar' => $this->avatar,
            'avatar_url' => $avatarUrl,
            'is_active' => (bool) ($this->is_active ?? true),
            'is_verified' => (bool) ($this->is_verified ?? false),
            'verified_at' => $this->isoDate($this->verified_at ?? null),
            'pending_approval' => (bool) ($this->pending_approval ?? false),
            'notify_login_email' => (bool) ($this->notify_login_email ?? false),
            'registration_role' => $this->registration_role,
            'last_login_at' => $this->isoDate($this->last_login_at ?? null),
            'created_at' => $this->isoDate($this->created_at ?? null),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'permissions' => $this->whenLoaded('roles', fn () => $this->getAllPermissions()->pluck('name')),
            'profile' => $this->when(
                $this->relationLoaded('profile'),
                fn () => $this->profile ? new ProfileResource($this->profile) : null
            ),
        ];
    }
}
