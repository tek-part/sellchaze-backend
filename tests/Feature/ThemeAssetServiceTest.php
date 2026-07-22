<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeAsset;
use App\Services\Themes\ThemeAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ThemeAssetServiceTest extends TestCase
{
    use RefreshDatabase;

    private ThemeAssetService $service;

    private Theme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->service = app(ThemeAssetService::class);
        $this->theme = Theme::create(['key' => 'demo', 'name' => 'Demo', 'status' => 'published']);
    }

    public function test_invalid_asset_type_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->store($this->theme, UploadedFile::fake()->create('x.txt', 5, 'text/plain'), 'not-a-type');
    }

    public function test_non_image_file_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->store($this->theme, UploadedFile::fake()->create('x.txt', 5, 'text/plain'), 'preview');
    }

    public function test_valid_image_is_stored_with_dimensions(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD not available for image generation.');
        }

        $asset = $this->service->store($this->theme, UploadedFile::fake()->image('shot.png', 800, 600), 'preview', 1);

        $this->assertInstanceOf(ThemeAsset::class, $asset);
        $this->assertSame('preview', $asset->type);
        $this->assertSame(800, $asset->width);
        $this->assertSame(600, $asset->height);
        Storage::disk('public')->assertExists($asset->path);

        $this->service->delete($asset);
        Storage::disk('public')->assertMissing($asset->path);
        $this->assertNull(ThemeAsset::find($asset->id));
    }
}
