<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailLogsApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(5, (int) $request->input('per_page', 20)));

        $paginator = EmailLog::query()
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (EmailLog $log) => [
                'id' => $log->id,
                'to' => $log->to,
                'subject' => $log->subject,
                'template_key' => $log->template_key,
                'status' => $log->status,
                'error_message' => $log->error_message,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
