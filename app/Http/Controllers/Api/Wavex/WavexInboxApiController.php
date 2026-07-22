<?php

namespace App\Http\Controllers\Api\Wavex;

use App\Http\Controllers\Controller;
use App\Models\WavexInboxMessage;
use App\Models\WavexSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WavexInboxApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'chat_id' => ['nullable', 'string', 'max:256'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $instanceId = WavexSetting::current()->instance_id;
        $perPage = (int) ($request->input('per_page', 50));

        $q = WavexInboxMessage::query()
            ->when($instanceId, fn ($query) => $query->where('instance_id', $instanceId))
            ->when($request->filled('chat_id'), fn ($query) => $query->where('chat_id', $request->string('chat_id')));

        if ($request->filled('chat_id')) {
            $q->orderBy('message_at')->orderBy('id');
        } else {
            $q->latest('message_at');
        }

        return response()->json($q->paginate($perPage));
    }
}
