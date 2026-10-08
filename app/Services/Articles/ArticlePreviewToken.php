<?php

namespace App\Services\Articles;

use App\Models\StoreArticle;
use Illuminate\Support\Facades\Crypt;

/** Expiring article previews are bound to one tenant, article and saved revision. */
class ArticlePreviewToken
{
    public function make(StoreArticle $article, int $ttl = 1800): string
    {
        return Crypt::encryptString(json_encode(['purpose' => 'article-preview', 'store' => $article->store_id, 'article' => $article->id, 'version' => $article->version, 'expires' => now()->timestamp + $ttl], JSON_THROW_ON_ERROR));
    }

    public function resolve(string $token, int $storeId): ?StoreArticle
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
            if (($data['purpose'] ?? null) !== 'article-preview' || ($data['store'] ?? null) !== $storeId || ($data['expires'] ?? 0) <= now()->timestamp) {
                return null;
            }

            return StoreArticle::query()->where('store_id', $storeId)->whereKey($data['article'] ?? 0)->where('version', $data['version'] ?? 0)->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
