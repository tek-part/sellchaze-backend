<?php

namespace App\Services\Commerce;

use Illuminate\Support\Facades\Http;

class StoreWebhookTransport
{
    public function __construct(private readonly WebhookDestination $destination) {}

    public function send(string $url, string $secret, string $eventId, array $payload): int
    {
        $target = $this->destination->resolve($url);
        $address = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
        $resolve = filter_var($target['host'], FILTER_VALIDATE_IP) ? [] : [$target['host'].':'.$target['port'].':'.$address];
        $response = Http::withHeaders(['secret' => $secret, 'X-Sellchaze-Event-Id' => $eventId])->acceptJson()
            ->timeout(10)->connectTimeout(5)->withoutRedirecting()
            ->withOptions(['curl' => [CURLOPT_RESOLVE => $resolve, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'],
                'allow_redirects' => false, 'verify' => true, 'proxy' => ''])
            ->post($url, $payload);

        return $response->status();
    }
}
