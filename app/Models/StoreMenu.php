<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreMenu extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'handle', 'name'];

    public function items(): HasMany
    {
        return $this->hasMany(StoreMenuItem::class)->withoutGlobalScope(StoreScope::class)->orderBy('position');
    }
}
