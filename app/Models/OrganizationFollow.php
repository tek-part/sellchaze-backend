<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationFollow extends Model
{
    protected $fillable = ['organization_id', 'user_id'];
}
