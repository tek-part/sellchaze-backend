<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcurementQuoteRevision extends Model
{
    protected $fillable = ['procurement_quote_id', 'version', 'snapshot', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'version' => 'integer'];
    }
}
