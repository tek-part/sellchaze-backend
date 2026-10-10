<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorePhoneBlockEvent extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'store_phone_block_id', 'actor_id', 'action', 'note', 'version'];

    protected $casts = ['version' => 'integer'];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
