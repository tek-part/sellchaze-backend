<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogsApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortDir = strtolower((string) $request->get('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query = ActivityLog::query()->with('actor')->orderBy('created_at', $sortDir);

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(function ($q) use ($term) {
                $q->where('action', 'like', $term)
                    ->orWhere('subject_type', 'like', $term)
                    ->orWhereHas('actor', function ($a) use ($term) {
                        $a->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term);
                    });
            });
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action')->trim());
        }
        if ($request->filled('event_type')) {
            $query->where('event_type', $request->string('event_type')->trim());
        }
        if ($request->filled('channel')) {
            $query->where('channel', $request->string('channel')->trim());
        }
        if ($request->filled('level')) {
            $query->where('level', $request->string('level')->trim());
        }
        if ($request->filled('actor_user_id')) {
            $query->where('actor_user_id', (int) $request->get('actor_user_id'));
        }
        if ($request->filled('module')) {
            $query->where('context->module', $request->string('module')->trim());
        }
        if ($request->filled('status_code')) {
            $query->where('context->status_code', (int) $request->get('status_code'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        $perPage = min(max((int) $request->get('per_page', 20), 5), 100);

        return ActivityLogResource::collection($query->paginate($perPage))->response();
    }

    public function destroy(ActivityLog $activityLog): JsonResponse
    {
        $activityLog->delete();

        return response()->json(['message' => 'OK']);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = ActivityLog::query()->whereIn('id', $validated['ids'])->delete();

        return response()->json(['message' => 'OK', 'deleted' => $deleted]);
    }
}
