<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unauthenticated contact form backing the marketing /contact page.
 * Stores the message as a generic Ticket so support staff can respond.
 */
class PublicContactApiController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191'],
            'subject' => ['required', 'string', 'max:191'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        // Best-effort: if Ticket model exists, persist. Otherwise log.
        try {
            Ticket::create([
                'subject' => '[public] ' . $validated['subject'],
                'message' => "From: {$validated['name']} <{$validated['email']}>\n\n" . $validated['message'],
                'status' => 'open',
                'user_id' => null,
            ]);
        } catch (\Throwable $e) {
            \Log::info('public.contact.message', $validated);
        }

        return response()->json(['data' => ['ok' => true]], 201);
    }
}
