<?php

namespace App\Http\Controllers\Api\Wavex;

use App\Http\Controllers\Controller;
use App\Models\WavexSetting;
use App\Services\Wavex\WawpClient;
use Illuminate\Http\JsonResponse;

class WavexSessionApiController extends Controller
{
    /**
     * POST /v2/session/create — provision a new instance and save instance_id locally.
     */
    public function create(): JsonResponse
    {
        $client = WawpClient::fromSettings();
        $res = $client->sessionCreate();

        if (! ($res['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'upstream_status' => $res['status'],
                'data' => $res['data'],
            ]);
        }

        $payload = is_array($res['data'] ?? null) ? $res['data'] : [];
        $instanceId = data_get($payload, 'instance_id')
            ?? data_get($payload, 'instanceId')
            ?? data_get($payload, 'data.instance_id')
            ?? data_get($payload, 'session.instance_id');

        if (is_string($instanceId) && $instanceId !== '') {
            $s = WavexSetting::current();
            $s->instance_id = $instanceId;
            $s->connection_status = 'created';
            $s->last_synced_at = now();
            $s->save();
        }

        return response()->json([
            'ok' => true,
            'upstream_status' => $res['status'],
            'data' => $res['data'],
        ]);
    }

    public function start(): JsonResponse
    {
        $client = WawpClient::fromSettings();
        $res = $client->sessionStart();
        $s = WavexSetting::current();
        $s->connection_status = is_array($res['data'] ?? null) ? ($res['data']['status'] ?? $res['data']['name'] ?? 'starting') : 'starting';
        $s->last_synced_at = now();
        $s->save();

        // Always HTTP 200 so the SPA can read JSON without axios rejecting on Wawp 404/502.
        return response()->json([
            'ok' => $res['ok'],
            'upstream_status' => $res['status'],
            'data' => $res['data'],
        ]);
    }

    public function stop(): JsonResponse
    {
        $client = WawpClient::fromSettings();
        $res = $client->sessionStop();
        $s = WavexSetting::current();
        $s->connection_status = 'stopped';
        $s->save();

        return response()->json([
            'ok' => $res['ok'],
            'upstream_status' => $res['status'],
            'data' => $res['data'],
        ]);
    }

    public function qr(): JsonResponse
    {
        $client = WawpClient::fromSettings();
        $res = $client->qrImage();
        $s = WavexSetting::current();
        $s->last_synced_at = now();
        $s->save();

        return response()->json([
            'ok' => $res['ok'],
            'upstream_status' => $res['status'],
            'data' => $res['data'],
        ]);
    }

    /**
     * GET — proxies Wawp POST /v2/session/info (+ optional GET /v2/session/me when WORKING).
     */
    public function info(): JsonResponse
    {
        $s = WavexSetting::current();
        $client = WawpClient::fromSettings();
        $res = $client->sessionInfo();

        $info = is_array($res['data'] ?? null) ? $res['data'] : null;
        if (is_array($info) && ! isset($info['status']) && isset($info['data']) && is_array($info['data'])) {
            $info = $info['data'];
        }

        if (($res['ok'] ?? false) && is_array($info)) {
            $st = $info['status'] ?? null;
            if (is_string($st) && $st !== '') {
                $s->connection_status = strtolower($st);
                $s->last_synced_at = now();
                $s->save();
            }
        }

        $profile = null;
        if (($res['ok'] ?? false)
            && is_array($info)
            && (($info['status'] ?? '') === 'WORKING')) {
            $me = $client->sessionMe();
            if ($me['ok'] ?? false) {
                $profile = $me['data'];
                if (is_array($profile) && isset($profile['data']) && is_array($profile['data']) && ! isset($profile['id'])) {
                    $profile = $profile['data'];
                }
            }
            if (! is_array($profile) && is_array($info['me'] ?? null)) {
                $profile = $info['me'];
            }

            $contactId = null;
            if (is_array($profile) && is_string($profile['id'] ?? null) && $profile['id'] !== '') {
                $contactId = $profile['id'];
            }
            if ($contactId === null && is_array($info['me'] ?? null) && is_string($info['me']['id'] ?? null) && $info['me']['id'] !== '') {
                $contactId = $info['me']['id'];
            }

            if (is_string($contactId) && $contactId !== '') {
                $picRes = $client->contactProfilePicture($contactId, false);
                $picData = is_array($picRes['data'] ?? null) ? $picRes['data'] : null;
                if (($picRes['ok'] ?? false) && is_array($picData)) {
                    if (isset($picData['data']) && is_array($picData['data']) && ! isset($picData['url'])) {
                        $picData = $picData['data'];
                    }
                    $picUrl = $picData['url'] ?? null;
                    if (is_string($picUrl) && $picUrl !== '') {
                        if (! is_array($profile)) {
                            $profile = [];
                        }
                        $profile['profilePictureUrl'] = $picUrl;
                    }
                }
            }
        }

        return response()->json([
            'ok' => $res['ok'],
            'upstream_status' => $res['status'],
            'instance_id' => $s->instance_id,
            'base_url' => $s->base_url,
            'info' => $info,
            'profile' => $profile,
        ]);
    }
}
