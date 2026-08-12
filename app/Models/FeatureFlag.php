<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FeatureFlag extends Model
{
    protected $fillable = ['key', 'name', 'description', 'enabled_by_default'];

    protected function casts(): array
    {
        return ['enabled_by_default' => 'boolean'];
    }

    /** @return BelongsToMany<Organization, $this> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_feature_flags')
            ->withPivot(['enabled', 'configuration', 'expires_at'])
            ->withTimestamps();
    }
}
