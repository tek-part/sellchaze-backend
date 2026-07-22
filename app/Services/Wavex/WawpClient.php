<?php

namespace App\Services\Wavex;

use App\Models\WavexSetting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp upstream client — all paths use Wawp API **v2** only (e.g. v2/session/*, v2/chats, v2/profile/*).
 *
 * @see https://api.wawp.net/en/docs/v2/start
 */
class WawpClient
{
    private Client $http;

    public function __construct(
        private WavexSetting $settings,
    ) {
        // Do not set a default Content-Type: application/json — it can leak onto GET / form POST
        // to Wawp and confuse proxies. JSON requests set Content-Type via Guzzle's `json` option.
        $this->http = new Client([
            'base_uri' => rtrim($this->settings->base_url ?: 'https://api.wawp.net', '/').'/',
            'timeout' => 120,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Replace HTML proxy/Apache error pages with a short JSON-shaped error so campaign last_error is readable.
     *
     * @param  array{ok: bool, status: int, data: mixed, raw?: string|null}  $result
     * @return array{ok: bool, status: int, data: mixed, raw?: string|null}
     */
    private function finalizeWawpResult(array $result): array
    {
        if ($result['ok'] ?? false) {
            return $result;
        }
        $data = $result['data'] ?? null;
        if (! is_string($data) || ! preg_match('/<html|<!DOCTYPE/i', $data)) {
            return $result;
        }
        $status = (int) ($result['status'] ?? 0);
        $base = rtrim($this->settings->base_url ?: 'https://api.wawp.net', '/');
        $host = strtolower((string) (parse_url($base, PHP_URL_HOST) ?: ''));
        $result['data'] = [
            'message' => 'The HTTP request returned an HTML error page (usually HTTP '.$status.' from a proxy or your host), not the Wawp JSON API. '
                .'In Wavex Settings set API base URL to https://api.wawp.net (not your Laravel app URL or localhost). '
                .'From the server running the queue worker, HTTPS to api.wawp.net must work. '
                .'For large campaign videos, set WAVEX_PUBLIC_STORAGE_URL or PUBLIC_DISK_URL (or APP_URL) to a public HTTPS base so Wawp fetches the file by URL instead of a huge base64 JSON body; run php artisan config:clear after .env changes.',
            'code' => 'upstream_html_error',
            'configured_host' => $host !== '' ? $host : null,
        ];
        $result['raw'] = strlen($data) > 1200 ? substr($data, 0, 400).'…' : $data;

        return $result;
    }

    /**
     * @param  bool  $requireInstance  When false, only access_token is used (e.g. POST /v2/session/create).
     * @param  array<string, mixed>  $extraRequestOptions  Merged into Guzzle request options (e.g. timeout).
     * @return array{ok: bool, status: int, data: mixed, raw?: string}
     */
    public function request(string $method, string $path, array $query = [], ?array $jsonBody = null, bool $requireInstance = true, array $extraRequestOptions = []): array
    {
        $token = $this->settings->getAccessTokenPlain();
        $instanceId = $this->settings->instance_id;
        if ($token === null || $token === '') {
            return ['ok' => false, 'status' => 400, 'data' => ['message' => 'Wavex is not configured (access token missing).']];
        }
        if ($requireInstance && ($instanceId === null || $instanceId === '')) {
            return ['ok' => false, 'status' => 400, 'data' => ['message' => 'Wavex is not configured (instance_id missing).']];
        }

        if ($requireInstance) {
            $query = array_merge($query, [
                'instance_id' => (string) $instanceId,
                'access_token' => $token,
            ]);
        } else {
            $query = array_merge($query, [
                'access_token' => $token,
            ]);
        }

        $uri = ltrim($path, '/');
        try {
            $opts = [
                'query' => $query,
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                ],
            ];
            if ($jsonBody !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
                $opts['json'] = $jsonBody === [] ? new \stdClass : $jsonBody;
            }
            if ($extraRequestOptions !== []) {
                $opts = array_merge($opts, $extraRequestOptions);
            }
            $response = $this->http->request($method, $uri, $opts);
        } catch (GuzzleException $e) {
            Log::warning('Wawp request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return ['ok' => false, 'status' => 502, 'data' => ['message' => 'Upstream connection failed.']];
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        $result = $this->finalizeWawpResult([
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : $body,
            'raw' => is_array($decoded) ? null : $body,
        ]);

        return $this->applyWawpMessagingSemantics($result, $uri);
    }

    /**
     * Wawp sometimes returns HTTP 2xx with a JSON error body ({ message, code }) instead of a message object.
     * Treat those as failure so campaigns are not marked "sent" when nothing was delivered.
     *
     * @param  array{ok: bool, status: int, data: mixed, raw?: string|null}  $result
     * @return array{ok: bool, status: int, data: mixed, raw?: string|null}
     */
    private function applyWawpMessagingSemantics(array $result, string $path): array
    {
        if (! ($result['ok'] ?? false)) {
            return $result;
        }
        $p = strtolower(ltrim($path, '/'));
        if (! str_contains($p, 'send/')) {
            return $result;
        }
        $data = $result['data'] ?? null;
        if (! is_array($data) || $this->wawpJsonLooksLikeSentMessage($data)) {
            return $result;
        }
        if ($this->wawpJsonLooksLikeSendErrorPayload($data)) {
            $result['ok'] = false;
            $http = (int) ($result['status'] ?? 500);
            $result['status'] = ($http >= 200 && $http < 300) ? 422 : $http;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private function wawpJsonLooksLikeSentMessage(array $d): bool
    {
        return self::wawpPayloadShowsImmediateMessage($d) || self::wawpPayloadIsUpstreamQueueOnly($d);
    }

    /**
     * JSON body indicates WhatsApp accepted the message (message object), i.e. not only an upstream job queue.
     *
     * @param  array<string, mixed>  $d
     */
    public static function wawpPayloadShowsImmediateMessage(array $d): bool
    {
        if (isset($d['_data']) && is_array($d['_data'])) {
            return true;
        }
        if (isset($d['id']) && is_array($d['id']) && (isset($d['id']['id']) || isset($d['id']['_serialized']))) {
            return true;
        }
        if (isset($d['key']) && is_array($d['key'])) {
            return true;
        }

        return false;
    }

    /**
     * Wawp accepted the send into its own queue (rate limits / load). HTTP 2xx but not yet delivered to WhatsApp.
     *
     * @param  array<string, mixed>  $d
     */
    public static function wawpPayloadIsUpstreamQueueOnly(array $d): bool
    {
        if (self::wawpPayloadShowsImmediateMessage($d)) {
            return false;
        }
        $apiStatus = isset($d['status']) ? strtolower(trim((string) $d['status'])) : '';
        if (in_array($apiStatus, ['queued', 'accepted', 'pending', 'scheduled'], true)) {
            return true;
        }
        $apiCode = isset($d['code']) ? strtolower(trim((string) $d['code'])) : '';
        if ($apiCode === 'success' && ! empty($d['job_id'])) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private function wawpJsonLooksLikeSendErrorPayload(array $d): bool
    {
        $apiCode = isset($d['code']) ? strtolower(trim((string) $d['code'])) : '';
        if ($apiCode === 'success') {
            return false;
        }
        $apiStatus = isset($d['status']) ? strtolower(trim((string) $d['status'])) : '';
        if (in_array($apiStatus, ['queued', 'accepted', 'pending', 'scheduled'], true)) {
            return false;
        }
        if (! empty($d['error'])) {
            return true;
        }
        if (array_key_exists('success', $d) && $d['success'] === false) {
            return true;
        }
        if (isset($d['code'], $d['message']) && is_string($d['message'])) {
            return true;
        }

        return false;
    }

    /**
     * GET binary media by absolute URL (browser cannot send JWT to Wawp file endpoints).
     *
     * @return array{ok: bool, status: int, body: string, content_type: string|null}
     */
    public function fetchBinary(string $absoluteUrl): array
    {
        $parts = parse_url($absoluteUrl);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return ['ok' => false, 'status' => 422, 'body' => '', 'content_type' => null];
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => false, 'status' => 422, 'body' => '', 'content_type' => null];
        }

        $host = strtolower((string) $parts['host']);
        $baseHost = strtolower((string) (parse_url($this->settings->base_url ?: 'https://api.wawp.net', PHP_URL_HOST) ?: 'api.wawp.net'));
        $isWawp = str_contains($host, 'wawp')
            || $host === $baseHost
            || str_ends_with($host, '.'.$baseHost);

        $target = $absoluteUrl;
        $headers = ['Accept' => '*/*'];
        if ($isWawp) {
            $token = $this->settings->getAccessTokenPlain();
            $instanceId = $this->settings->instance_id;
            if ($token === null || $token === '') {
                return ['ok' => false, 'status' => 400, 'body' => '', 'content_type' => null];
            }
            $q = [];
            if (! empty($parts['query'])) {
                parse_str((string) $parts['query'], $q);
            }
            if (! isset($q['access_token'])) {
                $q['access_token'] = $token;
            }
            if ($instanceId !== null && $instanceId !== '' && ! isset($q['instance_id'])) {
                $q['instance_id'] = $instanceId;
            }
            $parts['query'] = http_build_query($q);
            $target = $this->rebuildAbsoluteUrl($parts);
            $headers['Authorization'] = 'Bearer '.$token;
        }

        try {
            $response = $this->http->request('GET', $target, [
                'http_errors' => false,
                'headers' => $headers,
            ]);
        } catch (GuzzleException $e) {
            Log::warning('Wawp fetchBinary failed', ['url' => $absoluteUrl, 'error' => $e->getMessage()]);

            return ['ok' => false, 'status' => 502, 'body' => '', 'content_type' => null];
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $ct = $response->getHeaderLine('Content-Type');

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $body,
            'content_type' => $ct !== '' ? $ct : null,
        ];
    }

    /**
     * @param  array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string}  $parts
     */
    private function rebuildAbsoluteUrl(array $parts): string
    {
        $scheme = $parts['scheme'] ?? 'https';
        $url = $scheme.'://'.($parts['host'] ?? '');
        if (! empty($parts['port'])) {
            $url .= ':'.$parts['port'];
        }
        $url .= $parts['path'] ?? '';
        if (! empty($parts['query'])) {
            $url .= '?'.$parts['query'];
        }
        if (! empty($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }

    /**
     * POST /v2/session/create — provision instance (access_token only). See Wawp docs.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function sessionCreate(): array
    {
        $token = $this->settings->getAccessTokenPlain();
        if ($token === null || $token === '') {
            return ['ok' => false, 'status' => 400, 'data' => ['message' => 'Access token required to create a Wawp session.']];
        }

        return $this->request('POST', 'v2/session/create', [], [
            'access_token' => $token,
        ], false);
    }

    /**
     * POST /v2/session/start — boot WhatsApp engine.
     * Wawp docs: JSON body with instance_id + access_token.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function sessionStart(): array
    {
        $token = $this->settings->getAccessTokenPlain();
        $instanceId = $this->settings->instance_id;
        if ($token === null || $instanceId === null || $instanceId === '') {
            return ['ok' => false, 'status' => 400, 'data' => ['message' => 'Wavex is not configured (instance_id or access token missing).']];
        }

        return $this->request('POST', 'v2/session/start', [], [
            'instance_id' => $instanceId,
            'access_token' => $token,
        ]);
    }

    /**
     * POST /v2/session/stop
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function sessionStop(): array
    {
        $token = $this->settings->getAccessTokenPlain();
        $instanceId = $this->settings->instance_id;
        if ($token === null || $instanceId === null || $instanceId === '') {
            return ['ok' => false, 'status' => 400, 'data' => ['message' => 'Wavex is not configured (instance_id or access token missing).']];
        }

        return $this->request('POST', 'v2/session/stop', [], [
            'instance_id' => $instanceId,
            'access_token' => $token,
        ]);
    }

    /**
     * POST /v2/auth/qr-image — returns { qr: data:image/png;base64,... }
     * Wawp docs: JSON body with instance_id + access_token; wait 5–15s after start.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function qrImage(): array
    {
        $token = $this->settings->getAccessTokenPlain();
        $instanceId = $this->settings->instance_id;
        if ($token === null || $instanceId === null || $instanceId === '') {
            return ['ok' => false, 'status' => 400, 'data' => ['message' => 'Wavex is not configured (instance_id or access token missing).']];
        }

        return $this->request('POST', 'v2/auth/qr-image', [], [
            'instance_id' => $instanceId,
            'access_token' => $token,
        ]);
    }

    /**
     * POST /v2/session/info — status (e.g. WORKING), server name, me { id, pushName }.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function sessionInfo(): array
    {
        $token = $this->settings->getAccessTokenPlain();
        $instanceId = $this->settings->instance_id;
        if ($token === null || $instanceId === null || $instanceId === '') {
            return ['ok' => false, 'status' => 400, 'data' => ['message' => 'Wavex is not configured (instance_id or access token missing).']];
        }

        return $this->request('POST', 'v2/session/info', [], [
            'instance_id' => $instanceId,
            'access_token' => $token,
        ]);
    }

    /**
     * GET /v2/session/me — profile (id, pushName, platform, …).
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function sessionMe(): array
    {
        $token = $this->settings->getAccessTokenPlain();
        $instanceId = $this->settings->instance_id;
        if ($token === null || $instanceId === null || $instanceId === '') {
            return ['ok' => false, 'status' => 400, 'data' => ['message' => 'Wavex is not configured (instance_id or access token missing).']];
        }

        return $this->request('GET', 'v2/session/me');
    }

    /**
     * GET /v2/profile — public identity (name, about, picture) for the connected account.
     *
     * @see https://api.wawp.net/en/docs/v2/profile-overview
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function profileGet(): array
    {
        return $this->request('GET', 'v2/profile');
    }

    /**
     * POST /v2/profile/name — display name (push name).
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function profileSetName(string $name): array
    {
        return $this->request('POST', 'v2/profile/name', [], [
            'name' => $name,
        ]);
    }

    /**
     * POST /v2/profile/status — “About” / status text.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function profileSetStatus(string $status): array
    {
        return $this->request('POST', 'v2/profile/status', [], [
            'status' => $status,
        ]);
    }

    /**
     * POST /v2/profile/picture — set profile image (base64 or URL per Wawp tester; prefer square JPEG).
     *
     * @param  array<string, mixed>  $body  e.g. ['image' => base64] or fields from live docs
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function profileSetPicture(array $body): array
    {
        return $this->request('POST', 'v2/profile/picture', [], $body);
    }

    /**
     * GET /v2/contacts/profile-picture — { id, url } for a JID (use own @c.us id for account avatar).
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function contactProfilePicture(string $contactId, bool $refresh = false): array
    {
        $token = $this->settings->getAccessTokenPlain();
        $instanceId = $this->settings->instance_id;
        if ($token === null || $instanceId === null || $instanceId === '') {
            return ['ok' => false, 'status' => 400, 'data' => ['message' => 'Wavex is not configured (instance_id or access token missing).']];
        }

        return $this->request('GET', 'v2/contacts/profile-picture', [
            'contactId' => $contactId,
            'refresh' => $refresh ? 'true' : 'false',
        ]);
    }

    /**
     * GET /v2/contacts/all — address book + chat history (paginated; limit 1–1000 per Wawp docs).
     *
     * @param  array<string, scalar>  $extraQuery
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function listAllContacts(int $limit = 1000, int $offset = 0, array $extraQuery = []): array
    {
        $limit = min(1000, max(1, $limit));
        $offset = max(0, $offset);
        $query = array_merge([
            'limit' => $limit,
            'offset' => $offset,
            'sortBy' => 'name',
            'sortOrder' => 'ASC',
        ], $extraQuery);

        return $this->request('GET', 'v2/contacts/all', $query);
    }

    /**
     * GET /v2/lids — LID to phone mappings (paginated).
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function listLidMappings(int $limit = 500, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        return $this->request('GET', 'v2/lids', [
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * GET /v2/groups
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function listGroups(): array
    {
        return $this->request('GET', 'v2/groups');
    }

    /**
     * GET /v2/groups/{id}/participants
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function getGroupParticipants(string $groupId): array
    {
        $path = 'v2/groups/'.rawurlencode($groupId).'/participants';

        return $this->request('GET', $path);
    }

    /**
     * GET /v2/chats
     *
     * @param  array<string, scalar>  $extraQuery
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function listChats(array $extraQuery = []): array
    {
        return $this->request('GET', 'v2/chats', $extraQuery);
    }

    /**
     * GET /v2/chats/messages — chat history (see Wawp docs: chatId, limit, offset, downloadMedia).
     *
     * @param  array<string, scalar>  $extraQuery
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function getChatMessages(string $chatId, array $extraQuery = []): array
    {
        $query = array_merge([
            'chatId' => $chatId,
            'limit' => 50,
            'offset' => 0,
            'downloadMedia' => false,
        ], $extraQuery);

        return $this->request('GET', 'v2/chats/messages', $query);
    }

    /**
     * POST /v2/groups/create — body: subject, participants (per Wawp docs; may vary)
     *
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function createGroup(array $body): array
    {
        return $this->request('POST', 'v2/groups/create', [], $body);
    }

    /**
     * POST /v2/groups/{id}/participants/add
     *
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function addGroupParticipants(string $groupId, array $body): array
    {
        $path = 'v2/groups/'.rawurlencode($groupId).'/participants/add';

        return $this->request('POST', $path, [], $body);
    }

    /**
     * POST /v2/send/text
     *
     * Official samples: {@see https://api.wawp.net/en/docs/v2/send/text} — auth in query string;
     * JSON body carries chatId + message only (duplicating auth in the body can break validation).
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function sendText(string $chatId, string $message, ?string $replyTo = null): array
    {
        $text = $message !== '' ? $message : ' ';
        $payload = [
            'chatId' => $chatId,
            'message' => $text,
        ];
        if ($replyTo !== null && $replyTo !== '') {
            $payload['reply_to'] = $replyTo;
        }

        return $this->request('POST', 'v2/send/text', [], $payload);
    }

    /**
     * Wawp media endpoints validate "file[url] or url" — send both so JSON and form match their parser.
     *
     * @return array<string, string>
     */
    private function wawpMediaUrlFields(string $fileUrl, string $filename, string $mimetype): array
    {
        return [
            'url' => $fileUrl,
            'file[url]' => $fileUrl,
            'file[filename]' => $filename,
            'file[mimetype]' => $mimetype,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function wawpFileDataFields(string $base64Data, string $filename, string $mimetype): array
    {
        return [
            'file[data]' => $base64Data,
            'file[filename]' => $filename,
            'file[mimetype]' => $mimetype,
        ];
    }

    /**
     * Wawp /v2/send/video docs use string booleans in samples: "convert":"true", "asNote":"false".
     *
     * @return array<string, string>
     */
    private function wawpVideoBodyOptions(): array
    {
        return [
            'convert' => 'true',
            'asNote' => 'false',
        ];
    }

    /**
     * /v2/send/video — Wawp documents GET and POST. The live tester uses a long GET URL; some proxies break POST
     * while GET works. Order: GET (file[url] only, if query fits) → JSON POST (like sendImage) → form POST.
     *
     * @param  array<string, string>  $formParams  chatId, caption, convert, asNote, file[…] (flat JSON keys)
     * @param  array<string, mixed>|null  $jsonBodyWithNestedFile  Same fields with nested `file` — tried first for base64 sends
     * @return array{ok: bool, status: int, data: mixed, raw?: string}
     */
    private function postV2SendVideo(array $formParams, ?array $jsonBodyWithNestedFile = null): array
    {
        $longTimeout = ['timeout' => 300, 'connect_timeout' => 60];
        $isUrlOnly = isset($formParams['file[url]']) && ! isset($formParams['file[data]']);

        if (isset($formParams['file[url]']) && $this->videoSendGetQueryFits($formParams)) {
            $getResult = $this->getV2SendVideo($formParams, $longTimeout);
            if ($getResult['ok'] ?? false) {
                return $getResult;
            }
            $gs = (int) ($getResult['status'] ?? 0);
            if ($gs === 401 || $gs === 403) {
                return $getResult;
            }
        }

        // URL-based video: try x-www-form-urlencoded before JSON — some proxies choke on JSON to /v2/send/video
        // even when the body is small; base64 payloads still need JSON first (nested + flat) below.
        if ($isUrlOnly) {
            $formFirst = $this->postV2SendVideoForm($formParams, $longTimeout);
            if ($formFirst['ok'] ?? false) {
                return $formFirst;
            }
            $fst = (int) ($formFirst['status'] ?? 0);
            if ($fst === 401 || $fst === 403) {
                return $formFirst;
            }
        }

        $bodies = [];
        if ($jsonBodyWithNestedFile !== null) {
            $bodies[] = $jsonBodyWithNestedFile;
        }
        $bodies[] = $formParams;

        $jsonResult = null;
        foreach ($bodies as $body) {
            $jsonResult = $this->request('POST', 'v2/send/video', [], $body, true, $longTimeout);
            if ($jsonResult['ok'] ?? false) {
                return $jsonResult;
            }
            $st = (int) ($jsonResult['status'] ?? 0);
            if ($st === 401 || $st === 403) {
                return $jsonResult;
            }
        }

        if ($jsonResult === null) {
            return ['ok' => false, 'status' => 500, 'data' => ['message' => 'No video payload.']];
        }

        if ($isUrlOnly) {
            return $jsonResult;
        }

        if (! $this->videoSendShouldRetryWithForm($jsonResult)) {
            return $jsonResult;
        }

        return $this->postV2SendVideoForm($formParams, $longTimeout);
    }

    /**
     * Avoid over-long request lines (proxy 502 / 414) when using GET.
     */
    private function videoSendGetQueryFits(array $formParams): bool
    {
        $token = $this->settings->getAccessTokenPlain();
        $instanceId = $this->settings->instance_id;
        if ($token === null || $instanceId === null || $instanceId === '') {
            return false;
        }
        $query = array_merge([
            'instance_id' => (string) $instanceId,
            'access_token' => $token,
        ], $formParams);
        $q = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return strlen($q) <= 7000;
    }

    /**
     * GET /v2/send/video?… (Wawp docs / tester style).
     *
     * @param  array<string, mixed>  $guzzleOptions
     * @return array{ok: bool, status: int, data: mixed, raw?: string}
     */
    private function getV2SendVideo(array $formParams, array $guzzleOptions = []): array
    {
        $token = $this->settings->getAccessTokenPlain();
        $instanceId = $this->settings->instance_id;
        if ($token === null || $token === '') {
            return ['ok' => false, 'status' => 400, 'data' => ['message' => 'Wavex is not configured (access token missing).']];
        }
        if ($instanceId === null || $instanceId === '') {
            return ['ok' => false, 'status' => 400, 'data' => ['message' => 'Wavex is not configured (instance_id missing).']];
        }

        $opts = array_merge([
            'query' => array_merge([
                'instance_id' => (string) $instanceId,
                'access_token' => $token,
            ], $formParams),
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ],
        ], $guzzleOptions);

        try {
            $response = $this->http->request('GET', 'v2/send/video', $opts);
        } catch (GuzzleException $e) {
            Log::warning('Wawp send/video (GET) failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'status' => 502, 'data' => ['message' => 'Upstream connection failed.']];
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        $result = $this->finalizeWawpResult([
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : $body,
            'raw' => is_array($decoded) ? null : $body,
        ]);

        return $this->applyWawpMessagingSemantics($result, 'v2/send/video');
    }

    /**
     * @param  array<string, mixed>  $guzzleOptions
     * @return array{ok: bool, status: int, data: mixed, raw?: string}
     */
    private function postV2SendVideoForm(array $formParams, array $guzzleOptions = []): array
    {
        $token = $this->settings->getAccessTokenPlain();
        $instanceId = $this->settings->instance_id;
        if ($token === null || $token === '') {
            return ['ok' => false, 'status' => 400, 'data' => ['message' => 'Wavex is not configured (access token missing).']];
        }
        if ($instanceId === null || $instanceId === '') {
            return ['ok' => false, 'status' => 400, 'data' => ['message' => 'Wavex is not configured (instance_id missing).']];
        }

        $opts = array_merge([
            'query' => [
                'instance_id' => (string) $instanceId,
                'access_token' => $token,
            ],
            'form_params' => $formParams,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ],
        ], $guzzleOptions);

        try {
            $response = $this->http->request('POST', 'v2/send/video', $opts);
        } catch (GuzzleException $e) {
            Log::warning('Wawp send/video (form) failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'status' => 502, 'data' => ['message' => 'Upstream connection failed.']];
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        $result = $this->finalizeWawpResult([
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : $body,
            'raw' => is_array($decoded) ? null : $body,
        ]);

        return $this->applyWawpMessagingSemantics($result, 'v2/send/video');
    }

    /**
     * @param  array{ok: bool, status: int, data: mixed, raw?: string}  $result
     */
    private function videoSendShouldRetryWithForm(array $result): bool
    {
        if ($result['ok'] ?? false) {
            return false;
        }
        $status = (int) ($result['status'] ?? 0);
        if (in_array($status, [502, 503, 504, 413], true)) {
            return true;
        }
        $data = $result['data'] ?? null;
        if (is_string($data) && preg_match('/^\s*</', $data)) {
            return true;
        }
        if (is_array($data) && in_array((string) ($data['code'] ?? ''), ['missing_param', 'upstream_html_error'], true)) {
            return true;
        }

        return false;
    }

    /**
     * POST /v2/send/image — see Wawp docs (caption expected).
     *
     * @return array{ok: bool, status: int, data: mixed, raw?: string}
     */
    public function sendImage(string $chatId, string $fileUrl, string $filename, string $mimetype, ?string $caption = null): array
    {
        $payload = array_merge(
            ['chatId' => $chatId],
            $this->wawpMediaUrlFields($fileUrl, $filename, $mimetype),
            ['caption' => ($caption !== null && $caption !== '') ? $caption : ' '],
        );

        return $this->request('POST', 'v2/send/image', [], $payload);
    }

    /**
     * POST /v2/send/pdf — also used for generic documents per Wawp messaging guide.
     *
     * @return array{ok: bool, status: int, data: mixed, raw?: string}
     */
    public function sendPdf(string $chatId, string $fileUrl, string $filename, string $mimetype, ?string $caption = null): array
    {
        $payload = array_merge(
            ['chatId' => $chatId],
            $this->wawpMediaUrlFields($fileUrl, $filename, $mimetype),
            ['caption' => ($caption !== null && $caption !== '') ? $caption : ' '],
        );

        return $this->request('POST', 'v2/send/pdf', [], $payload);
    }

    /**
     * POST /v2/send/voice — OGG/Opus preferred; convert asks Wawp to transcode when supported.
     *
     * @return array{ok: bool, status: int, data: mixed, raw?: string}
     */
    public function sendVoice(string $chatId, string $fileUrl, string $filename, string $mimetype, bool $convert = true): array
    {
        $payload = array_merge(
            ['chatId' => $chatId],
            $this->wawpMediaUrlFields($fileUrl, $filename, $mimetype),
            ['convert' => $convert],
        );

        return $this->request('POST', 'v2/send/voice', [], $payload);
    }

    /**
     * POST /v2/send/video — file URL (caption optional).
     *
     * @return array{ok: bool, status: int, data: mixed, raw?: string}
     */
    public function sendVideo(string $chatId, string $fileUrl, string $filename, string $mimetype, ?string $caption = null): array
    {
        $captionText = ($caption !== null && $caption !== '') ? $caption : ' ';

        return $this->postV2SendVideo(array_merge(
            [
                'chatId' => $chatId,
                'caption' => $captionText,
            ],
            $this->wawpVideoBodyOptions(),
            $this->wawpMediaUrlFields($fileUrl, $filename, $mimetype),
        ));
    }

    /**
     * POST /v2/send/video with file[data] base64.
     *
     * @return array{ok: bool, status: int, data: mixed, raw?: string}
     */
    public function sendVideoData(string $chatId, string $base64Data, string $filename, string $mimetype, ?string $caption = null): array
    {
        $captionText = ($caption !== null && $caption !== '') ? $caption : ' ';

        $flat = array_merge(
            [
                'chatId' => $chatId,
                'caption' => $captionText,
            ],
            $this->wawpVideoBodyOptions(),
            $this->wawpFileDataFields($base64Data, $filename, $mimetype),
        );

        $jsonWithNestedFile = array_merge(
            [
                'chatId' => $chatId,
                'caption' => $captionText,
            ],
            $this->wawpVideoBodyOptions(),
            [
                'file' => [
                    'data' => $base64Data,
                    'filename' => $filename,
                    'mimetype' => $mimetype,
                ],
            ],
        );

        return $this->postV2SendVideo($flat, $jsonWithNestedFile);
    }

    /**
     * POST /v2/send/image with file[data] base64.
     *
     * @return array{ok: bool, status: int, data: mixed, raw?: string}
     */
    public function sendImageData(string $chatId, string $base64Data, string $filename, string $mimetype, ?string $caption = null): array
    {
        $captionText = ($caption !== null && $caption !== '') ? $caption : ' ';

        $flat = array_merge(
            ['chatId' => $chatId],
            $this->wawpFileDataFields($base64Data, $filename, $mimetype),
            ['caption' => $captionText],
        );

        $nested = array_merge(
            ['chatId' => $chatId, 'caption' => $captionText],
            [
                'file' => [
                    'data' => $base64Data,
                    'filename' => $filename,
                    'mimetype' => $mimetype,
                ],
            ],
        );

        return $this->postV2SendMediaJsonFirst('v2/send/image', $nested, $flat);
    }

    /**
     * JSON body: nested `file` first (Wawp parser), then flat file[data] keys.
     *
     * @param  array<string, mixed>  $jsonBodyWithNestedFile
     * @param  array<string, mixed>  $flatJsonBody
     * @return array{ok: bool, status: int, data: mixed, raw?: string}
     */
    private function postV2SendMediaJsonFirst(string $path, array $jsonBodyWithNestedFile, array $flatJsonBody): array
    {
        $first = $this->request('POST', $path, [], $jsonBodyWithNestedFile);
        if ($first['ok'] ?? false) {
            return $first;
        }
        $st = (int) ($first['status'] ?? 0);
        if ($st === 401 || $st === 403) {
            return $first;
        }

        return $this->request('POST', $path, [], $flatJsonBody);
    }

    /**
     * POST /v2/send/pdf with file[data] base64.
     *
     * @return array{ok: bool, status: int, data: mixed, raw?: string}
     */
    public function sendPdfData(string $chatId, string $base64Data, string $filename, string $mimetype, ?string $caption = null): array
    {
        $captionText = ($caption !== null && $caption !== '') ? $caption : ' ';

        $flat = array_merge(
            ['chatId' => $chatId],
            $this->wawpFileDataFields($base64Data, $filename, $mimetype),
            ['caption' => $captionText],
        );

        $nested = array_merge(
            ['chatId' => $chatId, 'caption' => $captionText],
            [
                'file' => [
                    'data' => $base64Data,
                    'filename' => $filename,
                    'mimetype' => $mimetype,
                ],
            ],
        );

        return $this->postV2SendMediaJsonFirst('v2/send/pdf', $nested, $flat);
    }

    /**
     * POST /v2/send/voice with file[data] base64.
     *
     * @return array{ok: bool, status: int, data: mixed, raw?: string}
     */
    public function sendVoiceData(string $chatId, string $base64Data, string $filename, string $mimetype, bool $convert = true): array
    {
        $flat = array_merge(
            ['chatId' => $chatId],
            $this->wawpFileDataFields($base64Data, $filename, $mimetype),
            ['convert' => $convert],
        );

        $nested = array_merge(
            ['chatId' => $chatId, 'convert' => $convert],
            [
                'file' => [
                    'data' => $base64Data,
                    'filename' => $filename,
                    'mimetype' => $mimetype,
                ],
            ],
        );

        return $this->postV2SendMediaJsonFirst('v2/send/voice', $nested, $flat);
    }

    public static function fromSettings(?WavexSetting $settings = null): self
    {
        return new self($settings ?? WavexSetting::current());
    }
}
