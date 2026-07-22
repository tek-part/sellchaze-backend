<?php

namespace App\Services\Wigpleasure;

use App\Models\Setting;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class WigpleasureExportClient
{
    private string $baseUrl;

    public function __construct(?string $baseUrlOverride = null)
    {
        $fromDb = Setting::get('wigpleasure_pull_base_url');
        $this->baseUrl = rtrim((string) ($baseUrlOverride ?? $fromDb ?: config('services.wigpleasure_pull_base_url', '')), '/');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '';
    }

    /**
     * @return array{data: array, meta: array<string, mixed>}
     *
     * @throws RequestException
     */
    public function get(string $resource, array $query = []): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Wigpleasure pull base URL is not configured.');
        }

        $url = $this->baseUrl.'/api/sellchase-export/'.$resource;
        $response = Http::acceptJson()
            ->timeout(120)
            ->get($url, $query);

        $response->throw();

        return $response->json();
    }
}
