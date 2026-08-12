<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Post;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\Themes\StoreThemeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SeedPerformanceFixture extends Command
{
    protected $signature = 'performance:seed {--products=500} {--posts=500} {--notifications=200}';

    protected $description = 'Create an idempotent, representative fixture for HTTP load gates';

    public function handle(StoreThemeService $themes): int
    {
        $email = (string) config('performance.fixture_email');
        $password = (string) config('performance.fixture_password');
        $user = User::withTrashed()->updateOrCreate(['email' => $email], [
            'name' => 'Sellchaze Performance Fixture',
            'password' => Hash::make($password),
            'is_active' => true,
            'pending_approval' => false,
            'deleted_at' => null,
        ]);
        $organization = Organization::query()->updateOrCreate(
            ['slug' => 'performance-fixture'],
            ['name' => 'Performance Fixture'],
        );
        $organization->memberships()->updateOrCreate(['user_id' => $user->id], [
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        $store = Store::query()->updateOrCreate(['slug' => 'performance-store'], [
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'owner_type' => 'merchant',
            'name' => 'Performance Store',
            'currency' => 'USD',
            'status' => 'active',
        ]);
        StoreDomain::query()->updateOrCreate(['host' => 'performance-store.sellchase.com'], [
            'store_id' => $store->id, 'type' => 'subdomain', 'is_primary' => true,
        ]);
        if (! $store->activeStoreTheme()->exists()) {
            $themes->installAndActivateDefault($store);
        }

        $category = Category::withoutGlobalScopes()->updateOrCreate(
            ['store_id' => $store->id, 'slug' => 'performance-products'],
            ['user_id' => $user->id, 'name' => 'Performance Products', 'is_active' => true],
        );

        $productCount = max(1, (int) $this->option('products'));
        for ($start = 1; $start <= $productCount; $start += 100) {
            $rows = [];
            for ($i = $start; $i < min($start + 100, $productCount + 1); $i++) {
                $rows[] = [
                    'store_id' => $store->id, 'user_id' => $user->id, 'category_id' => $category->id,
                    'name' => "Performance Product {$i}", 'slug' => "performance-product-{$i}",
                    'sku' => "PERF-{$i}", 'price' => 100 + $i, 'stock_quantity' => 1000,
                    'is_active' => true, 'is_featured' => $i <= 20, 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            Product::withoutGlobalScopes()->upsert($rows, ['store_id', 'slug'], ['name', 'price', 'stock_quantity', 'updated_at']);
        }

        $postCount = max(1, (int) $this->option('posts'));
        $existingPosts = Post::query()->where('user_id', $user->id)->count();
        for ($start = $existingPosts + 1; $start <= $postCount; $start += 100) {
            $rows = [];
            for ($i = $start; $i < min($start + 100, $postCount + 1); $i++) {
                $rows[] = [
                    'user_id' => $user->id, 'organization_id' => $organization->id,
                    'type' => 'update_news', 'body' => "Representative feed item {$i}",
                    'status' => 'published', 'published_at' => now()->subSeconds($i),
                    'created_at' => now()->subSeconds($i), 'updated_at' => now()->subSeconds($i),
                ];
            }
            Post::query()->insert($rows);
        }

        $notificationCount = max(1, (int) $this->option('notifications'));
        $existing = DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->count();
        for ($start = $existing + 1; $start <= $notificationCount; $start += 100) {
            $rows = [];
            for ($i = $start; $i < min($start + 100, $notificationCount + 1); $i++) {
                $rows[] = [
                    'id' => (string) Str::uuid(), 'type' => 'performance.fixture',
                    'notifiable_type' => User::class, 'notifiable_id' => $user->id,
                    'data' => json_encode(['message' => "Representative notification {$i}"]),
                    'created_at' => now()->subSeconds($i), 'updated_at' => now()->subSeconds($i),
                ];
            }
            DB::table('notifications')->insert($rows);
        }

        $this->components->info("Fixture ready: {$email} / performance-store.sellchase.com");

        return self::SUCCESS;
    }
}
