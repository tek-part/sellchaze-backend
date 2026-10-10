<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPersonalizationUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonalizationUploadController extends Controller
{
    use ResolvesStorefront;

    public function store(Request $request, int $product): JsonResponse
    {
        $data = $request->validate(['field_key' => ['required', 'string', 'max:80'],
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=4096,max_height=4096']]);
        $store = $this->currentStore($request);
        $model = Product::query()->where('store_id', $store->id)->where('is_active', true)->findOrFail($product);
        $field = collect($model->personalization_fields ?? [])->firstWhere('key', $data['field_key']);
        abort_unless($field && $field['type'] === 'image', 422, 'This product does not accept this image field.');
        $file = $request->file('file');
        $path = $file->store('product-personalization/'.$store->id, 'local');
        abort_unless($path, 503, 'The image could not be stored. Try again.');
        $token = bin2hex(random_bytes(32));
        try {
            $upload = ProductPersonalizationUpload::create(['id' => (string) Str::uuid(), 'store_id' => $store->id, 'product_id' => $model->id,
                'field_key' => $field['key'], 'token_hash' => hash('sha256', $token), 'path' => $path,
                'filename' => mb_substr(preg_replace('/[^\pL\pN ._-]/u', '', $file->getClientOriginalName()) ?: 'image.'.$file->extension(), 0, 160),
                'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'expires_at' => now()->addDays(7)]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json(['data' => ['token' => $token, 'filename' => $upload->filename, 'url' => $upload->downloadUrl(), 'expires_at' => $upload->expires_at]], 201);
    }

    /** Relative signed URLs work from both the merchant and storefront hosts. */
    public function show(int $store, string $upload): StreamedResponse
    {
        $file = ProductPersonalizationUpload::query()->where('store_id', $store)->findOrFail($upload);
        abort_if(! $file->claimed_at && $file->expires_at->isPast(), 404);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->response($file->path, $file->filename, ['Content-Type' => $file->mime,
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
