<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeedEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedEventController extends Controller
{
    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate(['events' => ['required', 'array', 'min:1', 'max:100'], 'events.*.event_uuid' => ['required', 'uuid'], 'events.*.post_id' => ['nullable', 'integer', 'exists:posts,id'], 'events.*.event_type' => ['required', 'in:impression,view_2s,dwell,expand,like,save,comment,share,follow,product_view,rfq_start,reel_complete,reel_replay'], 'events.*.value_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'], 'events.*.session_id' => ['nullable', 'string', 'max:100'], 'events.*.context' => ['nullable', 'array'], 'events.*.occurred_at' => ['required', 'date']]);
        $accepted = 0;
        foreach ($data['events'] as $event) {
            $row = FeedEvent::query()->firstOrCreate(['event_uuid' => $event['event_uuid']], [...$event, 'user_id' => $request->user()->id]);
            if ($row->wasRecentlyCreated) {
                $accepted++;
            }
        }

        return response()->json(['accepted' => $accepted]);
    }
}
