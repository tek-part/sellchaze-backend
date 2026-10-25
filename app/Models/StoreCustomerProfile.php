<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Merchant-owned tags, note and contact overrides for one directory customer key.
 *
 * @property int $id
 * @property int $store_id
 * @property string $customer_key
 * @property list<string>|null $tags
 * @property string|null $note
 * @property string|null $name
 * @property string|null $email
 * @property string|null $phone
 * @property Carbon|null $marketing_updated_at
 * @property string|null $marketing_source
 * @property int|null $marketing_updated_by
 * @property Carbon|null $updated_at
 */
class StoreCustomerProfile extends Model
{
    use BelongsToStore;

    public const MAX_TAGS = 20;

    public const MAX_TAG_LENGTH = 40;

    public const MAX_NOTE_LENGTH = 5000;

    protected $fillable = ['store_id', 'customer_key', 'tags', 'note', 'name', 'email', 'phone', 'marketing_updated_at', 'marketing_source', 'marketing_updated_by'];

    protected $casts = ['tags' => 'array', 'marketing_updated_at' => 'datetime'];
}
