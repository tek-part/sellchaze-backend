<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A user's saved dashboard list view (filters, sort, search, columns) for one table scope. */
class TableView extends Model
{
    protected $fillable = ['user_id', 'scope', 'name', 'state', 'position'];

    protected $casts = ['state' => 'array', 'position' => 'integer'];
}
