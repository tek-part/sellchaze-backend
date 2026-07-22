<?php

namespace App\Http\Controllers\Api\Wavex;

use App\Http\Controllers\Controller;
use App\Models\WavexTemplate;
use App\Support\Wavex\WavexTemplatePlainBody;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WavexTemplatesApiController extends Controller
{
    /** @var list<string> */
    private const ATTACHMENT_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'video/mp4',
        'video/quicktime',
        'video/webm',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function index(): JsonResponse
    {
        $rows = WavexTemplate::query()->latest()->paginate(50);
        $rows->setCollection(
            $rows->getCollection()->map(fn (WavexTemplate $t) => $this->transformTemplate($t)),
        );

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'body_html' => ['nullable', 'string', 'max:65535'],
            'body' => ['nullable', 'string', 'max:16000'],
            'remove_attachment' => ['sometimes', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:25600'],
        ]);

        $bodyHtml = $this->resolveBodyHtml($request);
        if ($bodyHtml === '') {
            throw ValidationException::withMessages([
                'body_html' => ['The message body is required.'],
            ]);
        }

        $this->validateAttachmentMime($request);

        $plain = WavexTemplatePlainBody::fromHtml($bodyHtml);

        $t = WavexTemplate::query()->create([
            'user_id' => $request->user()->id,
            'name' => $request->input('name'),
            'body' => $plain,
            'body_html' => $bodyHtml,
            'media_type' => null,
            'media_path' => null,
            'media_original_name' => null,
            'media_mime' => null,
        ]);

        if ($request->hasFile('attachment')) {
            $this->storeAttachment($t, $request->file('attachment'));
            $t->refresh();
        }

        return response()->json(['data' => $this->transformTemplate($t)], 201);
    }

    public function update(Request $request, WavexTemplate $wavexTemplate): JsonResponse
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'body_html' => ['nullable', 'string', 'max:65535'],
            'body' => ['nullable', 'string', 'max:16000'],
            'remove_attachment' => ['sometimes', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:25600'],
        ]);

        $bodyHtml = $this->resolveBodyHtml($request, $wavexTemplate);
        if ($bodyHtml === '') {
            throw ValidationException::withMessages([
                'body_html' => ['The message body is required.'],
            ]);
        }

        $this->validateAttachmentMime($request);

        $plain = WavexTemplatePlainBody::fromHtml($bodyHtml);

        if ($request->filled('name')) {
            $wavexTemplate->name = (string) $request->input('name');
        }
        $wavexTemplate->body = $plain;
        $wavexTemplate->body_html = $bodyHtml;

        if ($request->boolean('remove_attachment')) {
            $this->deleteAttachmentFile($wavexTemplate);
            $wavexTemplate->forceFill([
                'media_type' => null,
                'media_path' => null,
                'media_original_name' => null,
                'media_mime' => null,
            ]);
        }

        $wavexTemplate->save();

        if ($request->hasFile('attachment')) {
            $this->deleteAttachmentFile($wavexTemplate);
            $this->storeAttachment($wavexTemplate, $request->file('attachment'));
            $wavexTemplate->refresh();
        }

        return response()->json(['data' => $this->transformTemplate($wavexTemplate->fresh())]);
    }

    public function destroy(WavexTemplate $wavexTemplate): JsonResponse
    {
        $this->deleteAttachmentFile($wavexTemplate);
        $wavexTemplate->delete();

        return response()->json(['message' => 'OK']);
    }


    public function show(WavexTemplate $wavexTemplate): JsonResponse
    {
        return response()->json(['data' => $this->transformTemplate($wavexTemplate)]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ]);

        $templates = WavexTemplate::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $data['ids'])
            ->get();

        foreach ($templates as $t) {
            $this->deleteAttachmentFile($t);
            $t->delete();
        }

        return response()->json(['message' => 'OK', 'deleted' => $templates->count()]);
    }

    private function resolveBodyHtml(Request $request, ?WavexTemplate $existing = null): string
    {
        if ($request->filled('body_html')) {
            return (string) $request->input('body_html');
        }
        if ($request->filled('body')) {
            return '<p>'.htmlspecialchars((string) $request->input('body'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
        }
        if ($existing !== null && $existing->body_html !== null && $existing->body_html !== '') {
            return (string) $existing->body_html;
        }

        return '';
    }

    private function validateAttachmentMime(Request $request): void
    {
        if (! $request->hasFile('attachment')) {
            return;
        }
        $mime = $request->file('attachment')->getMimeType();
        if (! in_array($mime, self::ATTACHMENT_MIMES, true)) {
            throw ValidationException::withMessages([
                'attachment' => ['Invalid file type. Use an image, video, PDF, or Word document.'],
            ]);
        }
    }

    private function guessMediaType(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        return 'document';
    }

    private function storeAttachment(WavexTemplate $template, UploadedFile $file): void
    {
        $mime = $file->getMimeType();
        $dir = 'wavex-templates/'.$template->id;
        Storage::disk('public')->makeDirectory($dir);
        $path = $file->store($dir, 'public');

        $template->forceFill([
            'media_type' => $this->guessMediaType($mime),
            'media_path' => $path,
            'media_original_name' => $file->getClientOriginalName(),
            'media_mime' => $mime,
        ])->save();
    }

    private function deleteAttachmentFile(WavexTemplate $template): void
    {
        if ($template->media_path && Storage::disk('public')->exists($template->media_path)) {
            Storage::disk('public')->delete($template->media_path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function transformTemplate(WavexTemplate $t): array
    {
        $row = $t->toArray();
        $row['media_url'] = null;
        if ($t->media_path) {
            // SPA <img>/<video> must load from this API host (files live here). Do not use WAVEX_PUBLIC_STORAGE_URL / PUBLIC_DISK_URL.
            $path = ltrim(str_replace('\\', '/', $t->media_path), '/');
            $base = rtrim(request()->getSchemeAndHttpHost() ?: (string) config('app.url'), '/');
            $row['media_url'] = $base.'/storage/'.$path;
        }

        return $row;
    }
}
