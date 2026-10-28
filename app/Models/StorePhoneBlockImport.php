<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Queried with an explicit store_id: the background processor runs without a tenant context. */
class StorePhoneBlockImport extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'store_id', 'user_id', 'request_key', 'scope', 'status', 'file_name', 'file_path', 'format', 'total_rows', 'processed_rows',
        'imported', 'duplicates', 'invalid', 'skipped_inactive', 'invalid_samples', 'error_code', 'lease_token', 'lease_until', 'attempts', 'completed_at'];

    protected $hidden = ['file_path', 'lease_token'];

    protected $casts = ['invalid_samples' => 'encrypted:array', 'lease_until' => 'datetime', 'completed_at' => 'datetime',
        'total_rows' => 'integer', 'processed_rows' => 'integer', 'skipped_inactive' => 'integer', 'imported' => 'integer', 'duplicates' => 'integer', 'invalid' => 'integer', 'attempts' => 'integer'];
}
