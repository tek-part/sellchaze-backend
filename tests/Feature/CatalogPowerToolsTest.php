<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StorePriceChange;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** G3 (TAR-036): option ordering, bulk price changes, category membership edits and product sheets. */
class CatalogPowerToolsTest extends TestCase
{
    use RefreshDatabase;

    private array $auth;

    private Store $store;

    private User $owner;

    private string $base = '/api/v1/my-store/catalog/products';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Storage::fake('public');
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Tools', 'slug' => 'tools', 'currency' => 'EGP', 'status' => 'active']);
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($this->owner), 'Accept' => 'application/json'];
    }

    private function product(array $attributes = [], ?Store $store = null): Product
    {
        $store ??= $this->store;
        static $n = 0;
        $n++;

        return Product::withoutGlobalScopes()->create(array_merge(['store_id' => $store->id, 'name' => 'Product '.$n, 'slug' => 'product-'.$n.'-'.$store->id, 'price' => 100, 'is_active' => true], $attributes));
    }

    private function variant(Product $product, array $options, array $attributes = []): ProductVariant
    {
        return ProductVariant::withoutGlobalScopes()->create(array_merge(['store_id' => $product->store_id, 'store_product_id' => $product->id,
            'name' => implode(' / ', $options), 'options' => $options, 'is_active' => true], $attributes));
    }

    private function category(string $name, ?Store $store = null): Category
    {
        $store ??= $this->store;

        return Category::withoutGlobalScopes()->create(['store_id' => $store->id, 'user_id' => $store->owner_user_id, 'name' => $name, 'slug' => Str::slug($name).'-'.$store->id, 'is_active' => true]);
    }

    private function foreignStore(): Store
    {
        return Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Foreign', 'slug' => 'foreign-'.Str::random(6), 'currency' => 'EGP', 'status' => 'active']);
    }

    private function versions(Product $product): array
    {
        return collect($this->getJson($this->base.'/'.$product->id.'/variants', $this->auth)->assertOk()->json('data'))
            ->mapWithKeys(fn ($row) => [$row['id'] => $row['edit_version']])->all();
    }

    // ---------------------------------------------------------------- #15

    public function test_options_reorder_in_place_without_recreating_variants_and_feed_the_storefront_order(): void
    {
        $product = $this->product();
        $small = $this->variant($product, ['Size' => 'S'], ['stock_quantity' => 4, 'price_override' => 90]);
        $large = $this->variant($product, ['Size' => 'L'], ['position' => 1]);
        $medium = $this->variant($product, ['Size' => 'M'], ['position' => 2]);
        $versions = $this->versions($product);
        $order = [$medium->id, $small->id, $large->id];
        $this->putJson($this->base.'/'.$product->id.'/variants/order', ['variants' => array_map(fn ($id) => ['id' => $id, 'version' => $versions[$id]], $order)], $this->auth)
            ->assertOk()->assertJsonPath('data.0.id', $medium->id)->assertJsonPath('data.2.id', $large->id);
        $this->assertSame($order, $product->variants()->pluck('id')->all());
        $this->assertSame(4, $small->fresh()->stock_quantity);
        $this->assertEquals(90, $small->fresh()->price_override);
        $this->assertDatabaseCount('store_product_variants', 3);
        // The property/value display order persists on the product and is exposed to storefront pickers.
        $this->postJson($this->base.'/'.$product->id, ['_method' => 'PUT', 'name' => $product->name, 'option_display' => [['name' => 'Size', 'type' => 'buttons', 'values' => [['value' => 'L'], ['value' => 'M'], ['value' => 'S']]]]], $this->auth)->assertOk();
        $this->assertSame(['L', 'M', 'S'], array_column($product->fresh()->option_display[0]['values'], 'value'));

        // A stale version, a partial list and a foreign product are all refused without writing.
        $this->putJson($this->base.'/'.$product->id.'/variants/order', ['variants' => array_map(fn ($id) => ['id' => $id, 'version' => $versions[$id]], array_reverse($order))], $this->auth)->assertUnprocessable();
        $fresh = $this->versions($product);
        $this->putJson($this->base.'/'.$product->id.'/variants/order', ['variants' => [['id' => $small->id, 'version' => $fresh[$small->id]]]], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('variants');
        $this->assertSame($order, $product->variants()->pluck('id')->all());
        app(CurrentStore::class)->forget();
        $foreign = $this->product([], $this->foreignStore());
        $this->putJson($this->base.'/'.$foreign->id.'/variants/order', ['variants' => [['id' => 1, 'version' => str_repeat('a', 64)]]], $this->auth)->assertNotFound();
        Role::findByName('Merchant', 'web')->revokePermissionTo('products-edit');
        $this->putJson($this->base.'/'.$product->id.'/variants/order', ['variants' => array_map(fn ($id) => ['id' => $id, 'version' => $fresh[$id]], $order)], $this->auth)->assertForbidden();
    }

    // ---------------------------------------------------------------- #17

    public function test_bulk_add_and_remove_category_keeps_other_memberships_and_rejects_foreign_categories(): void
    {
        $shoes = $this->category('Shoes');
        $sale = $this->category('Sale');
        $a = $this->product(['category_id' => $shoes->id]);
        $a->additionalCategories()->sync([$shoes->id => ['store_id' => $this->store->id]]);
        $b = $this->product();
        $targets = fn () => collect($this->getJson($this->base.'?per_page=100', $this->auth)->json('data'))->whereIn('id', [$a->id, $b->id])->map(fn ($row) => ['id' => $row['id'], 'version' => $row['edit_version']])->values()->all();

        $this->putJson($this->base.'/bulk', ['products' => $targets(), 'changes' => ['add_category_id' => $sale->id]], $this->auth)->assertOk()->assertJsonPath('meta.updated', 2);
        $this->assertEqualsCanonicalizing([$shoes->id, $sale->id], $a->fresh()->additionalCategories->pluck('id')->all());
        $this->assertSame($shoes->id, $a->fresh()->category_id);
        $this->assertSame($sale->id, $b->fresh()->category_id);

        $this->putJson($this->base.'/bulk', ['products' => $targets(), 'changes' => ['remove_category_id' => $shoes->id]], $this->auth)->assertOk();
        $this->assertSame([$sale->id], $a->fresh()->additionalCategories->pluck('id')->all());
        $this->assertSame($sale->id, $a->fresh()->category_id);
        $this->assertSame([$sale->id], $b->fresh()->additionalCategories->pluck('id')->all());

        $this->putJson($this->base.'/bulk', ['products' => $targets(), 'changes' => ['remove_category_id' => $sale->id]], $this->auth)->assertOk();
        $this->assertNull($b->fresh()->category_id);
        $this->assertCount(0, $b->fresh()->additionalCategories);

        app(CurrentStore::class)->forget();
        $foreign = $this->category('Foreign', $this->foreignStore());
        $this->putJson($this->base.'/bulk', ['products' => $targets(), 'changes' => ['add_category_id' => $foreign->id]], $this->auth)->assertJsonValidationErrors('changes.add_category_id');
        $this->putJson($this->base.'/bulk', ['products' => $targets(), 'changes' => ['add_category_id' => $sale->id, 'category_id' => $shoes->id]], $this->auth)->assertJsonValidationErrors('changes.add_category_id');
    }

    // ---------------------------------------------------------------- #16

    private function priceInput(array $overrides = []): array
    {
        return array_replace_recursive(['direction' => 'increase', 'kind' => 'percent', 'value' => 10, 'apply_compare' => true, 'apply_variants' => true, 'rounding' => 'none'], $overrides);
    }

    public function test_category_price_change_previews_applies_atomically_only_to_that_category_and_reverts_in_one_action(): void
    {
        $shoes = $this->category('Shoes');
        $bags = $this->category('Bags');
        $inPrimary = $this->product(['category_id' => $shoes->id, 'price' => '99.99', 'compare_price' => '150.00']);
        $inExtra = $this->product(['category_id' => $bags->id, 'price' => '20.00']);
        $inExtra->additionalCategories()->sync([$bags->id => ['store_id' => $this->store->id], $shoes->id => ['store_id' => $this->store->id]]);
        $outside = $this->product(['category_id' => $bags->id, 'price' => '50.00']);
        $override = $this->variant($inPrimary, ['Size' => 'L'], ['price_override' => '120.00', 'compare_price' => '200.00']);
        $inherit = $this->variant($inPrimary, ['Size' => 'S']);
        $input = $this->priceInput(['scope' => ['type' => 'category', 'category_id' => $shoes->id]]);

        $preview = $this->postJson($this->base.'/price-changes/preview', $input, $this->auth)->assertOk()
            ->assertJsonPath('data.product_count', 2)->assertJsonPath('data.variant_count', 1)->assertJsonPath('data.out_of_range', 0)->json('data');
        $this->assertSame('109.99', collect($preview['samples'])->firstWhere('id', $inPrimary->id)['new_price']);
        $this->assertEquals(99.99, $inPrimary->fresh()->price);

        $this->postJson($this->base.'/price-changes', $input + ['fingerprint' => str_repeat('a', 64), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertStatus(409);
        $key = (string) Str::uuid();
        $id = $this->postJson($this->base.'/price-changes', $input + ['fingerprint' => $preview['fingerprint'], 'idempotency_key' => $key], $this->auth)->assertCreated()
            ->assertJsonPath('data.status', 'applied')->assertJsonPath('data.product_count', 2)->json('data.id');
        $this->postJson($this->base.'/price-changes', $input + ['fingerprint' => $preview['fingerprint'], 'idempotency_key' => $key], $this->auth)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertEquals('109.99', $inPrimary->fresh()->price);
        $this->assertEquals('165.00', $inPrimary->fresh()->compare_price);
        $this->assertEquals('22.00', $inExtra->fresh()->price);
        $this->assertEquals('50.00', $outside->fresh()->price);
        $this->assertEquals('132.00', $override->fresh()->price_override);
        $this->assertEquals('220.00', $override->fresh()->compare_price);
        $this->assertNull($inherit->fresh()->price_override);
        $this->assertDatabaseCount('store_price_change_items', 3);

        // A price edited after the change is left alone by the revert and reported.
        $inExtra->forceFill(['price' => '25.00'])->save();
        $this->postJson($this->base.'/price-changes/'.$id.'/revert', [], $this->auth)->assertOk()
            ->assertJsonPath('data.status', 'reverted')->assertJsonPath('data.revert_result.restored', 2)->assertJsonPath('data.revert_result.skipped', 1);
        $this->assertEquals('99.99', $inPrimary->fresh()->price);
        $this->assertEquals('150.00', $inPrimary->fresh()->compare_price);
        $this->assertEquals('120.00', $override->fresh()->price_override);
        $this->assertEquals('200.00', $override->fresh()->compare_price);
        $this->assertEquals('25.00', $inExtra->fresh()->price);
        $this->getJson($this->base.'/price-changes', $this->auth)->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.status', 'reverted');
    }

    public function test_price_change_rounding_amounts_selected_products_and_out_of_range_guard(): void
    {
        $a = $this->product(['price' => '10.40']);
        $b = $this->product(['price' => '3.00', 'compare_price' => '5.00']);
        $selected = ['scope' => ['type' => 'products', 'product_ids' => [$a->id, $b->id]]];
        $rounded = $this->postJson($this->base.'/price-changes/preview', $this->priceInput($selected + ['kind' => 'percent', 'value' => 15, 'rounding' => 'ceil']), $this->auth)->assertOk()->json('data.samples');
        $this->assertSame(['12.00', '4.00'], array_column($rounded, 'new_price'));
        $this->assertSame([null, '6.00'], array_column($rounded, 'new_compare_price'));
        $floor = $this->postJson($this->base.'/price-changes/preview', $this->priceInput($selected + ['kind' => 'amount', 'value' => '0.75', 'rounding' => 'floor', 'apply_compare' => false]), $this->auth)->assertOk()->json('data.samples');
        $this->assertSame(['11.00', '3.00'], array_column($floor, 'new_price'));
        $this->assertSame([null, '5.00'], array_column($floor, 'new_compare_price'));

        $decrease = $this->priceInput($selected + ['direction' => 'decrease', 'kind' => 'amount', 'value' => 6]);
        $preview = $this->postJson($this->base.'/price-changes/preview', $decrease, $this->auth)->assertOk()->assertJsonPath('data.out_of_range', 2)->json('data');
        $this->postJson($this->base.'/price-changes', $decrease + ['fingerprint' => $preview['fingerprint'], 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertJsonValidationErrors('value');
        $this->assertEquals('10.40', $a->fresh()->price);
        $this->postJson($this->base.'/price-changes/preview', $this->priceInput($selected + ['direction' => 'decrease', 'value' => 101]), $this->auth)->assertJsonValidationErrors('value');
        $this->assertDatabaseCount('store_price_changes', 0);
    }

    public function test_zero_prices_need_confirmation_and_option_compare_below_inherited_price_blocks_apply(): void
    {
        $a = $this->product(['price' => '10.00']);
        $b = $this->product(['price' => '0.40']);
        $selected = ['scope' => ['type' => 'products', 'product_ids' => [$a->id, $b->id]]];
        $free = $this->priceInput($selected + ['direction' => 'decrease', 'value' => 100]);
        $preview = $this->postJson($this->base.'/price-changes/preview', $free, $this->auth)->assertOk()->assertJsonPath('data.zero_prices', 2)->assertJsonPath('data.out_of_range', 0)->json('data');
        $this->postJson($this->base.'/price-changes', $free + ['fingerprint' => $preview['fingerprint'], 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertJsonValidationErrors('confirm_zero');
        $floor = $this->priceInput($selected + ['direction' => 'decrease', 'kind' => 'amount', 'value' => '0.10', 'rounding' => 'floor']);
        $this->postJson($this->base.'/price-changes/preview', $floor, $this->auth)->assertOk()->assertJsonPath('data.zero_prices', 1);
        $this->postJson($this->base.'/price-changes', $free + ['fingerprint' => $preview['fingerprint'], 'idempotency_key' => (string) Str::uuid(), 'confirm_zero' => true], $this->auth)->assertCreated();
        $this->assertEquals('0.00', $a->fresh()->price);

        $shirt = $this->product(['price' => '100.00']);
        $this->variant($shirt, ['Size' => 'M'], ['compare_price' => '110.00']);
        $raise = $this->priceInput(['scope' => ['type' => 'products', 'product_ids' => [$shirt->id]], 'value' => 20, 'apply_variants' => false]);
        $this->postJson($this->base.'/price-changes/preview', $raise, $this->auth)->assertOk()->assertJsonPath('data.out_of_range', 1)->assertJsonPath('data.option_compare_below_price', 1);
        $this->postJson($this->base.'/price-changes/preview', array_merge($raise, ['apply_variants' => true]), $this->auth)->assertOk()->assertJsonPath('data.out_of_range', 0);
    }

    public function test_price_changes_are_tenant_isolated_and_permission_gated(): void
    {
        $mine = $this->category('Mine');
        $product = $this->product(['category_id' => $mine->id]);
        app(CurrentStore::class)->forget();
        $foreignStore = $this->foreignStore();
        $foreignCategory = $this->category('Theirs', $foreignStore);
        $foreignProduct = $this->product(['category_id' => $foreignCategory->id], $foreignStore);
        $foreignChange = StorePriceChange::withoutGlobalScopes()->create(['store_id' => $foreignStore->id, 'idempotency_key' => (string) Str::uuid(), 'scope' => [], 'operation' => []]);

        $this->postJson($this->base.'/price-changes/preview', $this->priceInput(['scope' => ['type' => 'category', 'category_id' => $foreignCategory->id]]), $this->auth)->assertJsonValidationErrors('scope.category_id');
        $this->postJson($this->base.'/price-changes/preview', $this->priceInput(['scope' => ['type' => 'products', 'product_ids' => [$product->id, $foreignProduct->id]]]), $this->auth)->assertJsonValidationErrors('scope.product_ids');
        $this->postJson($this->base.'/price-changes/'.$foreignChange->id.'/revert', [], $this->auth)->assertNotFound();
        $this->getJson($this->base.'/price-changes', $this->auth)->assertOk()->assertJsonCount(0, 'data');
        Role::findByName('Merchant', 'web')->revokePermissionTo('products-edit');
        $this->postJson($this->base.'/price-changes/preview', $this->priceInput(['scope' => ['type' => 'category', 'category_id' => $mine->id]]), $this->auth)->assertForbidden();
    }

    // ---------------------------------------------------------------- #18

    private function xlsx(array $rows, string $name = 'products.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sheet-test-');
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    private function csv(array $rows): UploadedFile
    {
        $stream = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }
        rewind($stream);

        return UploadedFile::fake()->createWithContent('products.csv', "\xEF\xBB\xBF".stream_get_contents($stream));
    }

    /** @return list<array<string, mixed>> */
    private function readExport(string $content, string $format): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sheet-read-').'.'.$format;
        file_put_contents($path, $content);
        $rows = [];
        if ($format === 'csv') {
            $stream = fopen($path, 'r');
            fseek($stream, 3);
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $rows[] = $values;
            }
            fclose($stream);
        } else {
            $reader = new Reader;
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
                }
                break;
            }
            $reader->close();
        }
        $headers = array_shift($rows);

        return array_map(fn ($row) => array_combine($headers, array_pad($row, count($headers), '')), $rows);
    }

    public function test_sheet_export_has_one_row_per_variant_and_round_trips_into_update_by_id_or_sku(): void
    {
        $shoes = $this->category('Shoes');
        $shirt = $this->product(['name' => 'Shirt', 'sku' => 'SHIRT', 'category_id' => $shoes->id, 'price' => 200, 'compare_price' => 250]);
        $shirt->additionalCategories()->sync([$shoes->id => ['store_id' => $this->store->id]]);
        $shirt->forceFill(['option_display' => [['name' => 'Color', 'type' => 'buttons', 'values' => [['value' => 'Red']]], ['name' => 'Size', 'type' => 'buttons', 'values' => [['value' => 'M']]]]])->save();
        $red = $this->variant($shirt, ['Size' => 'M', 'Color' => 'Red'], ['sku' => 'SHIRT-RED', 'price_override' => 210, 'stock_quantity' => 5, 'track_inventory' => true]);
        $blue = $this->variant($shirt, ['Size' => 'M', 'Color' => 'Blue'], ['sku' => 'SHIRT-BLUE', 'stock_quantity' => 3, 'track_inventory' => true, 'reserved_quantity' => 2, 'position' => 1]);
        $mug = $this->product(['name' => 'Mug', 'sku' => 'MUG', 'price' => 50, 'stock_quantity' => 9, 'track_inventory' => true]);

        $csv = $this->post($this->base.'/sheet-export', ['format' => 'csv', 'product_ids' => [$shirt->id, $mug->id]], $this->auth)->assertOk()->assertDownload()->streamedContent();
        $rows = $this->readExport($csv, 'csv');
        $this->assertCount(3, $rows);
        $this->assertSame(['Color', 'Red', 'Size', 'M'], [$rows[0]['option1_name'], $rows[0]['option1_value'], $rows[0]['option2_name'], $rows[0]['option2_value']]);
        $this->assertSame(['SHIRT-RED', '210', '5', 'Shoes', (string) $shoes->id], [$rows[0]['variant_sku'], $rows[0]['variant_price'], $rows[0]['stock'], $rows[0]['categories'], $rows[0]['category_ids']]);
        $this->assertSame((string) $blue->id, $rows[1]['variant_id']);
        $this->assertSame(['', '9'], [$rows[2]['variant_id'], $rows[2]['stock']]);
        $xlsx = $this->readExport($this->post($this->base.'/sheet-export', ['format' => 'xlsx', 'category_id' => $shoes->id], $this->auth)->assertOk()->streamedContent(), 'xlsx');
        $this->assertCount(2, $xlsx);

        // Edit: shirt price + variant price by id, blue matched only by SKU, mug by SKU only with new stock.
        $edited = [array_keys($rows[0])];
        $rows[0]['price'] = '220';
        $rows[1]['price'] = '220';
        $rows[0]['variant_price'] = '230.50';
        $rows[1]['variant_id'] = '';
        $rows[1]['product_id'] = '';
        $rows[1]['variant_compare_price'] = '260';
        $rows[2]['product_id'] = '';
        $rows[2]['stock'] = '12';
        $rows[2]['status'] = 'draft';
        foreach ($rows as $row) {
            $edited[] = array_values($row);
        }
        $preview = $this->post($this->base.'/sheet-import/update/preview', ['file' => $this->xlsx($edited)], $this->auth)->assertOk()
            ->assertJsonPath('data.summary.errors', 0)->assertJsonPath('data.summary.update_products', 2)->assertJsonPath('data.summary.update_variants', 2)->json('data.rows');
        $this->assertSame(['update', 'update', 'update'], array_column($preview, 'action'));
        $this->assertEquals(200, $shirt->fresh()->price);
        $this->post($this->base.'/sheet-import/update', ['file' => $this->xlsx($edited), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertCreated()
            ->assertJsonPath('meta.updated', 2)->assertJsonPath('meta.created', 0);
        $this->assertEquals(220, $shirt->fresh()->price);
        $this->assertEquals(250, $shirt->fresh()->compare_price);
        $this->assertEquals('230.50', $red->fresh()->price_override);
        $this->assertEquals(260, $blue->fresh()->compare_price);
        $this->assertSame(12, $mug->fresh()->stock_quantity);
        $this->assertFalse($mug->fresh()->is_active);
        $this->assertDatabaseHas('store_inventory_movements', ['product_id' => $mug->id, 'stock_delta' => 3]);
        $this->assertSame([$shoes->id], $shirt->fresh()->additionalCategories->pluck('id')->all());
    }

    public function test_unchanged_export_reimports_with_zero_changes_and_never_writes_formulas(): void
    {
        $kids = $this->category('Shoes, kids');
        $sale = $this->category('=Sale, 2026');
        $risky = $this->product(['name' => '=HYPERLINK("http://x","y")', 'sku' => '+SKU-1', 'price' => '99.90', 'compare_price' => '120.00', 'category_id' => $sale->id, 'stock_quantity' => 3, 'track_inventory' => true]);
        $risky->additionalCategories()->sync([$sale->id => ['store_id' => $this->store->id], $kids->id => ['store_id' => $this->store->id]]);
        $quoted = $this->product(['name' => "'quoted name", 'price' => '999999999.99']);
        $sized = $this->product(['name' => '@sized', 'price' => 10, 'category_id' => $kids->id]);
        $sized->additionalCategories()->sync([$kids->id => ['store_id' => $this->store->id]]);
        $this->variant($sized, ['Size' => '-1', 'Fit' => 'Slim, tall'], ['sku' => '-V1', 'price_override' => '12.50', 'compare_price' => '15.00', 'stock_quantity' => 2, 'track_inventory' => true]);
        $this->variant($sized, ['Size' => '+2', 'Fit' => 'Slim, tall'], ['is_active' => false, 'position' => 1]);
        $ids = [$risky->id, $quoted->id, $sized->id];

        $csv = $this->post($this->base.'/sheet-export', ['format' => 'csv', 'product_ids' => $ids], $this->auth)->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("''quoted name", $csv);
        $xlsx = $this->post($this->base.'/sheet-export', ['format' => 'xlsx', 'product_ids' => $ids], $this->auth)->assertOk()->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'sheet-xml-');
        file_put_contents($path, $xlsx);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertSame('=HYPERLINK("http://x","y")', $this->readExport($xlsx, 'xlsx')[0]['name']);

        foreach (['csv' => UploadedFile::fake()->createWithContent('products.csv', $csv), 'xlsx' => new UploadedFile($path, 'products.xlsx', null, null, true)] as $format => $file) {
            $preview = $this->post($this->base.'/sheet-import/update/preview', ['file' => $file], $this->auth)->assertOk()->json('data');
            $this->assertSame(['rows' => 4, 'errors' => 0, 'unchanged' => 4, 'create_products' => 0, 'create_variants' => 0, 'update_products' => 0, 'update_variants' => 0], $preview['summary'], $format);
            $this->assertSame([[], [], [], []], array_column($preview['rows'], 'changes'), $format);
        }
        $this->assertDatabaseCount('categories', 2);
    }

    public function test_blank_cells_keep_values_and_the_clear_token_clears_optional_values(): void
    {
        $shoes = $this->category('Shoes');
        $product = $this->product(['name' => 'Boot', 'sku' => 'BOOT', 'price' => 80, 'compare_price' => 100, 'category_id' => $shoes->id]);
        $product->additionalCategories()->sync([$shoes->id => ['store_id' => $this->store->id]]);
        $header = ['product_id', 'name', 'status', 'price', 'compare_price', 'sku', 'category_ids'];
        $blank = $this->post($this->base.'/sheet-import/update/preview', ['file' => $this->csv([$header, [$product->id, '', '', '', '', '', '']])], $this->auth)->assertOk();
        $blank->assertJsonPath('data.summary.unchanged', 1)->assertJsonPath('data.rows.0.changes', []);
        $sheet = [$header, [$product->id, '', '', '', '[clear]', '[CLEAR]', '[clear]']];
        $this->post($this->base.'/sheet-import/update', ['file' => $this->csv($sheet), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertCreated()->assertJsonPath('meta.updated', 1);
        $fresh = $product->fresh();
        $this->assertSame(['Boot', null, '80.00', null, null], [$fresh->name, $fresh->sku, (string) $fresh->price, $fresh->compare_price, $fresh->category_id]);
        $this->assertCount(0, $fresh->additionalCategories);
        $errors = $this->post($this->base.'/sheet-import/update/preview', ['file' => $this->csv([$header, [$product->id, '[clear]', '', '[clear]', '', '', '999']])], $this->auth)->assertOk()->json('data.rows.0.errors');
        $this->assertSame(['required', 'required', 'category_not_found'], array_column($errors, 'code'));
    }

    public function test_sheet_dry_run_reports_per_row_errors_and_apply_is_all_or_nothing(): void
    {
        $product = $this->product(['name' => 'Cap', 'sku' => 'CAP', 'price' => 30]);
        $this->product(['name' => 'Hat', 'sku' => 'HAT', 'price' => 40]);
        $scarf = $this->product(['name' => 'Scarf', 'sku' => 'SCARF', 'price' => 40]);
        $sized = $this->product(['name' => 'Sock', 'price' => 10]);
        $s = $this->variant($sized, ['Size' => 'S'], ['reserved_quantity' => 3, 'stock_quantity' => 5, 'track_inventory' => true]);
        app(CurrentStore::class)->forget();
        $foreign = $this->product(['name' => 'Foreign', 'sku' => 'FOREIGN'], $this->foreignStore());
        $sheet = [['product_id', 'name', 'price', 'sku', 'option1_name', 'option1_value', 'stock'],
            [$product->id, 'Cap', '35', 'CAP', '', '', ''],
            [$foreign->id, 'Foreign', '1', 'FOREIGN', '', '', ''],
            ['', 'Hat', 'abc', 'HAT', '', '', ''],
            [$product->id, 'Cap', '35', 'CAP', '', '', ''],
            [$scarf->id, 'Scarf', '40', 'CAP', '', '', ''],
            [$sized->id, 'Sock', '10', '', 'Size', 'XL', ''],
            [$sized->id, 'Sock', '10', '', 'Size', 'S', '2']];
        $rows = collect($this->post($this->base.'/sheet-import/update/preview', ['file' => $this->csv($sheet)], $this->auth)->assertOk()
            ->assertJsonPath('data.summary.errors', 6)->json('data.rows'))->keyBy('row');
        $this->assertSame('update', $rows[2]['action']);
        $this->assertSame('product_not_found', $rows[3]['errors'][0]['code']);
        $this->assertSame('invalid_price', $rows[4]['errors'][0]['code']);
        $this->assertSame('duplicate_row', $rows[5]['errors'][0]['code']);
        $this->assertContains('sku_duplicate', array_column($rows[6]['errors'], 'code'));
        $this->assertContains('sku_taken', array_column($rows[6]['errors'], 'code'));
        $this->assertSame('variant_not_found', $rows[7]['errors'][0]['code']);
        $this->assertSame('stock_reserved', $rows[8]['errors'][0]['code']);
        $this->post($this->base.'/sheet-import/update', ['file' => $this->csv($sheet), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertUnprocessable();
        $this->assertEquals(30, $product->fresh()->price);
        $this->assertSame(5, $s->fresh()->stock_quantity);
        $this->assertEquals('Foreign', Product::withoutGlobalScopes()->find($foreign->id)->name);
        $this->post($this->base.'/sheet-import/update/preview', ['file' => $this->csv([['color', 'price'], ['red', '1']])], $this->auth)->assertJsonValidationErrors('file');
    }

    public function test_sheet_create_mode_builds_products_with_ordered_options_and_variant_prices(): void
    {
        $women = $this->category('Women, dresses');
        $sheet = [['name', 'price', 'compare_price', 'category_ids', 'option1_name', 'option1_value', 'option2_name', 'option2_value', 'variant_sku', 'variant_price', 'stock', 'track_inventory'],
            ['Dress', '500', '600', (string) $women->id, 'Size', 'M', 'Color', 'Red', 'DR-M-R', '520', '4', '1'],
            ['Dress', '', '', '', 'Size', 'L', 'Color', 'Red', 'DR-L-R', '', '2', '1'],
            ['Dress', '', '', '', 'Size', 'M', 'Color', 'Black', 'DR-M-B', '510', '0', '1'],
            ['Belt', '80', '', '', '', '', '', '', '', '', '7', '1']];
        $preview = $this->post($this->base.'/sheet-import/create/preview', ['file' => $this->csv($sheet)], $this->auth)->assertOk()
            ->assertJsonPath('data.summary.errors', 0)->assertJsonPath('data.summary.create_products', 2)->assertJsonPath('data.summary.create_variants', 3)->json('data');
        $this->assertDatabaseCount('products', 0);
        $ids = $this->post($this->base.'/sheet-import/create', ['file' => $this->csv($sheet), 'idempotency_key' => (string) Str::uuid()], $this->auth)->assertCreated()
            ->assertJsonPath('meta.created', 2)->json('meta.ids');
        $dress = Product::query()->findOrFail($ids[0]);
        $this->assertEquals(500, $dress->price);
        $this->assertSame(['M / Red', 'L / Red', 'M / Black'], $dress->variants->pluck('name')->all());
        $this->assertEquals(520, $dress->variants[0]->price_override);
        $this->assertNull($dress->variants[1]->price_override);
        $this->assertSame(4, $dress->variants[0]->stock_quantity);
        $this->assertSame(['Size', 'Color'], array_column($dress->option_display, 'name'));
        $this->assertSame(['M', 'L'], array_column($dress->option_display[0]['values'], 'value'));
        $this->assertSame('Women, dresses', $dress->category->name);
        $this->assertDatabaseCount('categories', 1);
        $this->assertSame(7, Product::query()->findOrFail($ids[1])->stock_quantity);
        // Re-importing the same SKUs as new products is refused per row; update mode is the way to change them.
        $again = $this->post($this->base.'/sheet-import/create/preview', ['file' => $this->csv($sheet)], $this->auth)->assertOk()->json('data.rows');
        $this->assertContains('sku_taken', array_column($again[0]['errors'], 'code'));
        Role::findByName('Merchant', 'web')->revokePermissionTo('products-create');
        $this->post($this->base.'/sheet-import/create/preview', ['file' => $this->csv($sheet)], $this->auth)->assertForbidden();
        $this->post($this->base.'/sheet-import/update/preview', ['file' => $this->csv([['sku', 'price'], ['DR-M-R', '1']])], $this->auth)->assertOk();
    }
}
