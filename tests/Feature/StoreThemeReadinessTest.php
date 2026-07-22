<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 4: minimal theme-readiness schema on stores (no themes, no rendering).
 */
class StoreThemeReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_table_has_theme_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('stores', 'theme_id'));
        $this->assertTrue(Schema::hasColumn('stores', 'theme_settings'));
    }

    public function test_theme_settings_persist_and_cast_to_array(): void
    {
        $store = Store::create([
            'owner_user_id' => User::factory()->create()->id,
            'owner_type' => 'merchant', 'name' => 'Nike', 'slug' => 'nike',
            'currency' => 'USD', 'status' => 'active',
            'theme_id' => 7, 'theme_settings' => ['primary' => '#111', 'layout' => 'grid'],
        ]);

        $fresh = $store->fresh();
        $this->assertSame(7, (int) $fresh->theme_id);
        $this->assertIsArray($fresh->theme_settings);
        $this->assertSame('#111', $fresh->theme_settings['primary']);
    }
}
