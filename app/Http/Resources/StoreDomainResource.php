<?php

namespace App\Http\Resources;

use App\Models\StoreDomain;
use App\Services\Stores\StoreDomainService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoreDomain
 */
class StoreDomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(StoreDomainService::class);

        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'host' => $this->host,
            'type' => $this->type,
            'status' => $this->status,
            'is_primary' => (bool) $this->is_primary,
            'is_servable' => $this->isServable(),

            // Everything the owner UI needs to render DNS instructions without
            // the frontend having to know the record format.
            'verification' => [
                'record_type' => 'TXT',
                'record_name' => $this->when(
                    $this->isCustom(),
                    fn () => $service->verificationRecordName($this->resource),
                ),
                'record_value' => $this->when(
                    $this->isCustom(),
                    fn () => $this->verificationTxtValue(),
                ),
                'verified_at' => $this->verified_at,
                'last_checked_at' => $this->last_checked_at,
                'last_error' => $this->last_error,
                'token_expires_at' => $this->verification_token_expires_at,
                'token_expired' => $this->tokenHasExpired(),
            ],

            'dns' => [
                'txt_ok' => (bool) $this->dns_txt_ok,
                'target_ok' => (bool) $this->dns_target_ok,
                'target_type' => $this->dns_target_type,
                'expected_cname' => config('sellchase.storefront.domains.cname_target'),
                'expected_a' => config('sellchase.storefront.domains.a_target'),
            ],

            'ssl' => [
                'status' => $this->ssl_status,
                'provider' => $this->ssl_provider,
                'issuer' => $this->ssl_issuer,
                'fingerprint' => $this->ssl_fingerprint,
                'san' => $this->ssl_san,
                'issued_at' => $this->ssl_issued_at,
                'expires_at' => $this->ssl_expires_at,
                'days_remaining' => $this->sslDaysRemaining(),
                'renewal_attempts' => $this->ssl_renewal_attempts,
                'last_error' => $this->ssl_last_error,
            ],

            'health_score' => $this->health_score,
            'locked_until' => $this->locked_until,
            'is_locked' => $this->isLocked(),
            'verification_attempts' => $this->verification_attempts,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
