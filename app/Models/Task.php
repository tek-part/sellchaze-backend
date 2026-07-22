<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'in_progress', 'done'];

    protected $fillable = [
        'assigned_to_user_id',
        'assigned_by_user_id',
        'title',
        'description',
        'status',
        'due_date',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
