<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailTemplatesApiController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = EmailTemplate::query()->get()->keyBy('key');
        $defs = EmailTemplateService::definitions();
        $data = collect($defs)->map(function (array $d) use ($rows) {
            $r = $rows->get($d['key']);

            return [
                'key' => $d['key'],
                'description' => $d['description'],
                'placeholders' => $d['placeholders'] ?? '',
                'subject' => $r->subject ?? null,
                'has_custom_body' => $r && $r->body_html !== null && trim((string) $r->body_html) !== '',
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function show(string $key): JsonResponse
    {
        if (! in_array($key, EmailTemplate::KEYS, true)) {
            return response()->json(['message' => 'Unknown template key.'], 404);
        }

        $row = EmailTemplate::query()->where('key', $key)->first();
        $sampleVars = [
            'order_code' => 'DEMO-001',
            'product_name' => 'Sample product',
            'app_name' => config('app.name'),
        ];
        $default = app(EmailTemplateService::class)->render($key, $sampleVars);
        $def = collect(EmailTemplateService::definitions())->firstWhere('key', $key);

        return response()->json([
            'key' => $key,
            'subject' => $row->subject ?? '',
            'body_html' => $row->body_html ?? '',
            'default_subject' => $default['subject'],
            'default_body_html' => $default['html'],
            'placeholders' => is_array($def) ? ($def['placeholders'] ?? '') : '',
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        if (! in_array($key, EmailTemplate::KEYS, true)) {
            return response()->json(['message' => 'Unknown template key.'], 404);
        }

        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:998'],
            'body_html' => ['nullable', 'string', 'max:65535'],
        ]);

        $template = EmailTemplate::query()->updateOrCreate(
            ['key' => $key],
            [
                'subject' => $validated['subject'] ?? null,
                'body_html' => $validated['body_html'] ?? null,
            ]
        );

        return response()->json([
            'message' => __('Email template saved.'),
            'key' => $template->key,
            'subject' => $template->subject,
            'body_html' => $template->body_html,
        ]);
    }
}
