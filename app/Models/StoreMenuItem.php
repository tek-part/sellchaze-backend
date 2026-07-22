<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreMenuItem extends Model
{
    use BelongsToStore;

    public const TYPES = ['internal', 'category', 'product', 'url'];

    protected $fillable = [
        'store_menu_id', 'store_id', 'parent_id', 'label', 'type', 'target', 'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(StoreMenu::class, 'store_menu_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(StoreMenuItem::class, 'parent_id')->withoutGlobalScope(StoreScope::class)->orderBy('position');
    }
}
