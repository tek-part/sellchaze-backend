<?php

namespace App\Http\Controllers\Api\Wavex;

use App\Http\Controllers\Controller;
use App\Services\Wavex\WawpClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WavexGroupsApiController extends Controller
{
    public function index(): JsonResponse
    {
        $client = WawpClient::fromSettings();
        $res = $client->listGroups();

        return response()->json([
            'ok' => $res['ok'],
            'data' => $res['data'],
        ], $res['ok'] ? 200 : $res['status']);
    }

    public function participants(string $groupId): JsonResponse
    {
        $client = WawpClient::fromSettings();
        $res = $client->getGroupParticipants($groupId);

        return response()->json([
            'ok' => $res['ok'],
            'data' => $res['data'],
        ], $res['ok'] ? 200 : $res['status']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'participants' => ['required', 'array', 'min:1'],
            'participants.*' => ['string', 'max:64'],
        ]);

        $client = WawpClient::fromSettings();
        $res = $client->createGroup($data);

        return response()->json([
            'ok' => $res['ok'],
            'data' => $res['data'],
        ], $res['ok'] ? 201 : $res['status']);
    }

    public function addParticipants(Request $request, string $groupId): JsonResponse
    {
        $data = $request->validate([
            'participants' => ['required', 'array', 'min:1'],
            'participants.*' => ['string', 'max:64'],
        ]);

        $client = WawpClient::fromSettings();
        $res = $client->addGroupParticipants($groupId, $data);

        return response()->json([
            'ok' => $res['ok'],
            'data' => $res['data'],
        ], $res['ok'] ? 200 : $res['status']);
    }
}
