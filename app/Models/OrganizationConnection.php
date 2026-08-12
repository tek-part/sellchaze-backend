<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_a_id
 * @property int $organization_b_id
 * @property int $initiator_organization_id
 * @property int $requested_by_user_id
 * @property int|null $responded_by_user_id
 * @property string $status
 * @property string|null $message
 * @property Carbon|null $responded_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 */
class OrganizationConnection extends Model
{
    protected $fillable = [
        'organization_a_id', 'organization_b_id', 'initiator_organization_id',
        'requested_by_user_id', 'responded_by_user_id', 'status', 'message',
        'responded_at', 'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'organization_a_id' => 'integer',
            'organization_b_id' => 'integer',
            'initiator_organization_id' => 'integer',
            'responded_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organizationA(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_a_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organizationB(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_b_id');
    }

    public function includes(int $organizationId): bool
    {
        return $this->organization_a_id === $organizationId || $this->organization_b_id === $organizationId;
    }

    public function otherOrganizationId(int $organizationId): int
    {
        return $this->organization_a_id === $organizationId
            ? $this->organization_b_id
            : $this->organization_a_id;
    }
}
