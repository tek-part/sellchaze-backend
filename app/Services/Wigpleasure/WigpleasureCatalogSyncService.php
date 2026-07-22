<?php

namespace App\Services\Wigpleasure;

use App\Models\Category;
use App\Models\User;
use App\Support\ProductImageUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WigpleasureCatalogSyncService
{
    public function __construct(
        private readonly WigpleasureExportClient $client
    ) {}

    /**
     * @return array{categories: int, products: int, attributes: int, attribute_values: int, errors: list<array{message: string}>}
     */
    public function syncAll(?WigpleasureExportClient $clientOverride = null): array
    {
        $client = $clientOverride ?? $this->client;

        $errors = [];
        $categories = 0;
        $products = 0;
        $attributes = 0;
        $attributeValues = 0;

        try {
            $categories = $this->syncCategories($client);
        } catch (\Throwable $e) {
            Log::error('Wigpleasure catalog: categories sync failed', ['error' => $e->getMessage()]);
            $errors[] = ['message' => 'Categories: '.$e->getMessage()];
        }

        try {
            [$attributes, $attributeValues] = $this->syncAttributes($client);
        } catch (\Throwable $e) {
            Log::error('Wigpleasure catalog: attributes sync failed', ['error' => $e->getMessage()]);
            $errors[] = ['message' => 'Attributes: '.$e->getMessage()];
        }

        try {
            $products = $this->syncProducts($client);
        } catch (\Throwable $e) {
            Log::error('Wigpleasure catalog: products sync failed', ['error' => $e->getMessage()]);
            $errors[] = ['message' => 'Products: '.$e->getMessage()];
        }

        Cache::forget('categories_list');
        Cache::forget('attributes_list');

        return [
            'categories' => $categories,
            'products' => $products,
            'attributes' => $attributes,
            'attribute_values' => $attributeValues,
            'errors' => $errors,
        ];
    }

    private function syncCategories(WigpleasureExportClient $client): int
    {
        $n = 0;
        $page = 1;
        do {
            $json = $client->get('categories', ['per_page' => 100, 'page' => $page]);
            foreach ($json['data'] ?? [] as $row) {
                $wpId = (int) ($row['id'] ?? 0);
                if ($wpId <= 0) {
                    continue;
                }
                $nameEn = trim((string) ($row['name_en'] ?? ''));
                $nameAr = trim((string) ($row['name_ar'] ?? ''));
                if ($nameEn === '' && $nameAr === '') {
                    continue;
                }
                Category::query()->updateOrCreate(
                    ['wigpleasure_category_id' => $wpId],
                    [
                        'name_en' => $nameEn !== '' ? $nameEn : $nameAr,
                        'name_ar' => $nameAr !== '' ? $nameAr : $nameEn,
                    ]
                );
                $n++;
            }
            $last = (int) ($json['meta']['last_page'] ?? 1);
            $page++;
        } while ($page <= $last);

        return $n;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function syncAttributes(WigpleasureExportClient $client): array
    {
        $nAttr = 0;
        $nVal = 0;
        $page = 1;
        do {
            $json = $client->get('attributes', ['per_page' => 100, 'page' => $page]);
            foreach ($json['data'] ?? [] as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $nameEn = (string) ($row['name_en'] ?? '');
                $nameAr = (string) ($row['name_ar'] ?? '');
                $name = $nameEn !== '' ? $nameEn : ($nameAr !== '' ? $nameAr : 'Attribute '.$id);

                DB::table('attributes')->updateOrInsert(
                    ['id' => $id],
                    [
                        'name' => mb_substr($name, 0, 255),
                        'type' => 'select',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
                $nAttr++;

                foreach ($row['values'] ?? [] as $v) {
                    $vid = (int) ($v['id'] ?? 0);
                    if ($vid <= 0) {
                        continue;
                    }
                    $vEn = (string) ($v['value_en'] ?? '');
                    $vAr = (string) ($v['value_ar'] ?? '');
                    $val = $vEn !== '' ? $vEn : ($vAr !== '' ? $vAr : '');

                    DB::table('attribute_values')->updateOrInsert(
                        ['id' => $vid],
                        [
                            'attribute_id' => $id,
                            'value' => $val,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                    $nVal++;
                }
            }
            $last = (int) ($json['meta']['last_page'] ?? 1);
            $page++;
        } while ($page <= $last);

        return [$nAttr, $nVal];
    }

    private function syncProducts(WigpleasureExportClient $client): int
    {
        $ownerId = $this->defaultProductOwnerId();
        $fallbackCategoryId = $this->ensureFallbackCategoryId();
        $n = 0;
        $page = 1;
        do {
            $json = $client->get('products', ['per_page' => 50, 'page' => $page]);
            foreach ($json['data'] ?? [] as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $wpCatId = isset($row['wigpleasure_category_id']) ? (int) $row['wigpleasure_category_id'] : 0;
                $categoryId = $wpCatId > 0
                    ? (int) (Category::query()->where('wigpleasure_category_id', $wpCatId)->value('id') ?? 0)
                    : 0;
                if ($categoryId <= 0) {
                    $categoryId = $fallbackCategoryId;
                }

                $titleEn = (string) ($row['title_en'] ?? '');
                $titleAr = (string) ($row['title_ar'] ?? '');
                $title = (string) ($row['title'] ?? '');
                $name = $titleEn !== '' ? $titleEn : ($titleAr !== '' ? $titleAr : ($title !== '' ? $title : 'Product '.$id));

                $image = ProductImageUrl::canonicalizeFromSource($row['image_url'] ?? null);

                DB::table('products')->updateOrInsert(
                    ['id' => $id],
                    [
                        'name' => mb_substr($name, 0, 255),
                        'description' => '',
                        'image' => $image,
                        'user_id' => $ownerId,
                        'category_id' => $categoryId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
                $n++;
            }
            $last = (int) ($json['meta']['last_page'] ?? 1);
            $page++;
        } while ($page <= $last);

        return $n;
    }

    private function ensureFallbackCategoryId(): int
    {
        $id = Category::query()->orderBy('id')->value('id');
        if ($id) {
            return (int) $id;
        }
        $c = Category::query()->create([
            'name_en' => 'Default',
            'name_ar' => 'Default',
        ]);

        return (int) $c->id;
    }

    private function defaultProductOwnerId(): int
    {
        $configured = config('services.sellchase_merchant_user_id');
        if ($configured !== null && $configured !== '') {
            $u = User::query()->find((int) $configured);
            if ($u && ($u->hasRole('Merchant') || $u->hasRole('Admin'))) {
                return (int) $u->id;
            }
        }
        $merchant = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'Merchant'))->orderBy('id')->first();
        if ($merchant) {
            return (int) $merchant->id;
        }
        $admin = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'Admin'))->orderBy('id')->first();
        if ($admin) {
            return (int) $admin->id;
        }
        $any = User::query()->orderBy('id')->value('id');

        return $any ? (int) $any : 1;
    }
}
