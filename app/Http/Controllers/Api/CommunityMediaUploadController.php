<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCommunityMedia;
use App\Models\MediaAsset;
use App\Models\MediaUpload;
use App\Support\Community\MediaPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CommunityMediaUploadController extends Controller
{
    public function initiate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:512'],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.config('community.max_upload_bytes')],
            'mime' => ['required', 'string', Rule::in(config('community.allowed_mimes'))],
            'checksum_sha256' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/i'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ]);
        $this->authorizeOrganization($request, $data['organization_id'] ?? null);
        $kind = str_starts_with($data['mime'], 'image/') ? 'image' : (str_starts_with($data['mime'], 'video/') ? 'video' : 'document');
        $chunkSize = (int) config('community.chunk_size');
        $total = (int) ceil($data['size_bytes'] / $chunkSize);

        [$asset, $upload] = DB::transaction(function () use ($request, $data, $kind, $chunkSize, $total) {
            $asset = MediaAsset::create([
                'uuid' => (string) Str::uuid(), 'user_id' => $request->user()->id,
                'organization_id' => $data['organization_id'] ?? null,
                'disk' => config('community.upload_disk'), 'original_name' => $data['name'],
                'kind' => $kind, 'mime' => $data['mime'], 'size_bytes' => $data['size_bytes'],
                'checksum_sha256' => $data['checksum_sha256'] ?? null, 'status' => 'initiated',
            ]);
            $upload = MediaUpload::create([
                'media_asset_id' => $asset->id, 'user_id' => $request->user()->id,
                'chunk_size' => $chunkSize, 'total_chunks' => $total,
                'status' => 'uploading', 'expires_at' => now()->addHours((int) config('community.upload_ttl_hours')),
            ]);

            return [$asset, $upload];
        });

        return response()->json(['data' => $this->uploadPayload($upload->load('parts'), $asset)], 201);
    }

    public function show(Request $request, MediaUpload $upload): JsonResponse
    {
        $this->authorizeUpload($request, $upload);

        return response()->json(['data' => $this->uploadPayload($upload->load('parts'), $upload->asset)]);
    }

    public function part(Request $request, MediaUpload $upload, int $part): JsonResponse
    {
        $this->authorizeUpload($request, $upload);
        abort_if($upload->expires_at->isPast(), 410, 'Upload session expired.');
        abort_unless($part >= 1 && $part <= $upload->total_chunks, 422, 'Invalid part number.');
        $request->validate(['chunk' => ['required', 'file', 'max:'.(int) ceil(($upload->chunk_size + 1024) / 1024)], 'checksum_sha256' => ['nullable', 'string', 'size:64']]);
        $file = $request->file('chunk');
        $checksum = hash_file('sha256', $file->getRealPath());
        if ($request->filled('checksum_sha256') && ! hash_equals(strtolower((string) $request->string('checksum_sha256')), $checksum)) {
            return response()->json(['message' => 'Chunk checksum mismatch.'], 422);
        }
        $path = 'community-chunks/'.$upload->id.'/'.str_pad((string) $part, 8, '0', STR_PAD_LEFT).'.part';
        Storage::disk(config('community.chunk_disk'))->put($path, fopen($file->getRealPath(), 'rb'));

        DB::transaction(function () use ($upload, $part, $file, $checksum, $path) {
            $upload->parts()->updateOrCreate(['part_number' => $part], ['size_bytes' => $file->getSize(), 'checksum_sha256' => $checksum, 'temporary_path' => $path]);
            $upload->update(['uploaded_chunks' => $upload->parts()->count(), 'status' => 'uploading']);
        });

        return response()->json(['part_number' => $part, 'checksum_sha256' => $checksum, 'uploaded_chunks' => $upload->refresh()->uploaded_chunks, 'total_chunks' => $upload->total_chunks]);
    }

    public function complete(Request $request, MediaUpload $upload): JsonResponse
    {
        $this->authorizeUpload($request, $upload);
        $upload->load(['parts' => fn ($q) => $q->orderBy('part_number'), 'asset']);
        if ($upload->status === 'completed') {
            return response()->json(['data' => MediaPresenter::asset($upload->asset->load('variants'))]);
        }
        abort_unless($upload->parts->count() === $upload->total_chunks, 422, 'Upload is incomplete.');

        $tmpDir = storage_path('app/community-assembly');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }
        $tmp = $tmpDir.'/'.$upload->id.'.upload';
        $out = fopen($tmp, 'wb');
        try {
            foreach ($upload->parts as $part) {
                $in = Storage::disk(config('community.chunk_disk'))->readStream($part->temporary_path);
                if (! $in) {
                    throw new \RuntimeException('Missing upload chunk '.$part->part_number);
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        $asset = $upload->asset;
        if (filesize($tmp) !== (int) $asset->size_bytes) {
            @unlink($tmp);

            return response()->json(['message' => 'Assembled file size mismatch.'], 422);
        }
        $checksum = hash_file('sha256', $tmp);
        if ($asset->checksum_sha256 && ! hash_equals(strtolower($asset->checksum_sha256), $checksum)) {
            @unlink($tmp);

            return response()->json(['message' => 'File checksum mismatch.'], 422);
        }
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: $asset->mime;
        abort_unless(in_array($detected, config('community.allowed_mimes'), true), 422, 'Unsupported file content.');
        $extension = $this->extensionFor($detected);
        $key = 'community/'.$asset->kind.'/'.now()->format('Y/m').'/'.$asset->uuid.'.'.$extension;
        $read = fopen($tmp, 'rb');
        Storage::disk($asset->disk)->put($key, $read, ['visibility' => 'public']);
        fclose($read);
        @unlink($tmp);

        DB::transaction(function () use ($upload, $asset, $key, $checksum, $detected) {
            $asset->update(['object_key' => $key, 'checksum_sha256' => $checksum, 'mime' => $detected, 'status' => 'uploaded']);
            $upload->update(['status' => 'completed', 'uploaded_chunks' => $upload->total_chunks]);
        });
        Storage::disk(config('community.chunk_disk'))->deleteDirectory('community-chunks/'.$upload->id);
        ProcessCommunityMedia::dispatch($asset->id);

        return response()->json(['data' => MediaPresenter::asset($asset->fresh()->load('variants'))]);
    }

    public function destroy(Request $request, MediaUpload $upload): JsonResponse
    {
        $this->authorizeUpload($request, $upload);
        abort_if($upload->status === 'completed', 409, 'Completed uploads cannot be cancelled.');
        Storage::disk(config('community.chunk_disk'))->deleteDirectory('community-chunks/'.$upload->id);
        $upload->asset()->delete();

        return response()->json(['message' => 'Upload cancelled.']);
    }

    private function uploadPayload(MediaUpload $upload, MediaAsset $asset): array
    {
        return ['upload_id' => $upload->id, 'asset' => MediaPresenter::asset($asset), 'chunk_size' => $upload->chunk_size, 'total_chunks' => $upload->total_chunks, 'uploaded_parts' => $upload->parts->pluck('part_number')->values(), 'expires_at' => $upload->expires_at?->toIso8601String()];
    }

    private function authorizeUpload(Request $request, MediaUpload $upload): void
    {
        abort_unless((int) $upload->user_id === (int) $request->user()->id, 403);
    }

    private function authorizeOrganization(Request $request, ?int $organizationId): void
    {
        if (! $organizationId) {
            return;
        }
        abort_unless($request->user()->organizationMemberships()->where('organization_id', $organizationId)->where('status', 'active')->exists(), 403);
    }

    private function extensionFor(string $mime): string
    {
        return ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov', 'application/pdf' => 'pdf'][$mime] ?? 'bin';
    }
}
