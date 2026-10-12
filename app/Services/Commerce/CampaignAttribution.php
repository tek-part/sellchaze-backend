<?php

namespace App\Services\Commerce;

/** Two optional visitor-supplied campaign labels, never URLs, click IDs or contact data. */
class CampaignAttribution
{
    public const RULES = [
        'marketing_attribution' => ['nullable', 'array:source,campaign'],
        'marketing_attribution.source' => ['required_with:marketing_attribution', 'string', 'max:100', 'regex:/\S/u', 'not_regex:/[\p{C}<>]/u'],
        'marketing_attribution.campaign' => ['required_with:marketing_attribution', 'string', 'max:200', 'regex:/\S/u', 'not_regex:/[\p{C}<>]/u'],
    ];

    public function snapshot(?array $data): ?array
    {
        if (! $data) {
            return null;
        }

        return ['source' => trim((string) preg_replace('/\s+/u', ' ', $data['source'])),
            'campaign' => trim((string) preg_replace('/\s+/u', ' ', $data['campaign']))];
    }
}
