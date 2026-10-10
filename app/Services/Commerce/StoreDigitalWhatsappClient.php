<?php

namespace App\Services\Commerce;

use App\Services\Wavex\WawpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Fixed Wawp v2 origin, per-store credentials; no admin singleton or private error logging. */
class StoreDigitalWhatsappClient
{
    private function request(string $path, array $credentials, array $body = []): Response
    {
        return Http::acceptJson()->timeout(20)->connectTimeout(5)->withOptions(['allow_redirects' => false])
            ->post('https://api.wawp.net/v2/'.$path, $body + [
                'instance_id' => $credentials['instance_id'], 'access_token' => $credentials['access_token'],
            ]);
    }

    public function verify(array $credentials): bool
    {
        try {
            $response = $this->request('session/info', $credentials);

            return $response->successful() && $response->json('status') === 'WORKING';
        } catch (\Throwable) {
            return false;
        }
    }

    /** A provider acceptance is not a delivery acknowledgement; ambiguous outcomes are never retried. */
    public function send(array $credentials, string $phone, string $text): array
    {
        try {
            $response = $this->request('send/text', $credentials, ['chatId' => $phone.'@c.us', 'message' => $text]);
            $data = $response->json();
            if ($response->successful() && is_array($data) && (WawpClient::wawpPayloadShowsImmediateMessage($data) || WawpClient::wawpPayloadIsUpstreamQueueOnly($data))) {
                $reference = data_get($data, 'id._serialized') ?? data_get($data, 'key.id') ?? ($data['job_id'] ?? null);

                return ['state' => 'accepted', 'reference' => is_string($reference) && preg_match('/^[A-Za-z0-9@._:-]{1,180}$/', $reference) ? $reference : null];
            }

            return ['state' => in_array($response->status(), [400, 401, 403, 404, 422], true) ? 'rejected' : 'unknown'];
        } catch (\Throwable) {
            return ['state' => 'unknown'];
        }
    }

    public static function phone(string $phone): ?string
    {
        $normalized = preg_replace('/[\s()+.-]/', '', $phone);
        if (str_starts_with($normalized, '00')) {
            $normalized = substr($normalized, 2);
        }

        return preg_match('/^[1-9][0-9]{7,14}$/', $normalized) ? $normalized : null;
    }
}
