<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorePageRevision extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_page_id', 'store_id', 'revision_number', 'snapshot', 'created_by',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'revision_number' => 'integer',
    ];

    /** @return BelongsTo<StorePage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(StorePage::class, 'store_page_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
