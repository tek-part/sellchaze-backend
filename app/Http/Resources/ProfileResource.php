<?php

namespace App\Http\Resources;

use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Profile
 */
class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'biography' => $this->biography,
            'photo' => $this->photo,
            'company' => $this->company,
            'country' => $this->country,
            'city' => $this->city,
            'address' => $this->address,
            'gender' => $this->gender,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'birthdate' => $this->birthdate,
            'social_media' => $this->social_media,
            'cover_photo' => $this->cover_photo,
            'cover_color' => $this->cover_color,
            'cover_photo_url' => $this->cover_photo
                ? (str_starts_with((string) $this->cover_photo, 'http')
                    ? $this->cover_photo
                    : asset('storage/uploads/users/original/'.$this->cover_photo))
                : null,
            'photo_url' => $this->photo
                ? (str_starts_with((string) $this->photo, 'http')
                    ? $this->photo
                    : asset('storage/uploads/users/original/'.$this->photo))
                : null,
            'website' => $this->website,
            'tagline' => $this->tagline,
            'is_public' => (bool) ($this->is_public ?? true),
        ];
    }
}
