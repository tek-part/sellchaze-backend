<?php

namespace Tests\Feature;

use App\Services\Media\ImageDerivatives;
use App\Services\Storefront\ResponsiveImageUrl;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageDerivativesTest extends TestCase
{
    private function png(string $path, int $width = 1200, int $height = 800): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 150, 90));
        ob_start();
        imagepng($image);
        Storage::disk('public')->put($path, (string) ob_get_clean());
        imagedestroy($image);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config()->set('filesystems.disks.public.url', 'https://api.sellchaze.test/storage');
        config()->set('sellchase.storefront.images.transformer_url', null);
    }

    public function test_first_request_renders_a_resized_webp_and_later_ones_reuse_it(): void
    {
        $this->png('stores/12/media/hero.png');
        $url = app(ImageDerivatives::class)->url('https://api.sellchaze.test/storage/stores/12/media/hero.png', 600);
        $this->assertSame('https://api.sellchaze.test/storage/_img/w640/stores/12/media/hero.png.webp', $url);

        $response = $this->get('/storage/_img/w640/stores/12/media/hero.png.webp')->assertOk()
            ->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->assertNull($response->headers->get('Set-Cookie'));
        $file = Storage::disk('public')->path('_img/w640/stores/12/media/hero.png.webp');
        $this->assertFileExists($file);
        $this->assertSame([640, 426], array_slice(getimagesize($file), 0, 2));
    }

    public function test_small_images_are_not_upscaled(): void
    {
        $this->png('stores/1/logo.png', 200, 100);
        $this->get('/storage/_img/w1920/stores/1/logo.png.webp')->assertOk();
        $this->assertSame(200, getimagesize(Storage::disk('public')->path('_img/w1920/stores/1/logo.png.webp'))[0]);
    }

    public function test_invalid_widths_paths_and_types_are_rejected(): void
    {
        $this->png('stores/1/a.png');
        Storage::disk('public')->put('stores/1/notes.txt', 'hello');
        $this->get('/storage/_img/w777/stores/1/a.png.webp')->assertNotFound();
        $this->get('/storage/_img/w640/stores/1/missing.png.webp')->assertNotFound();
        $this->get('/storage/_img/w640/stores/1/notes.txt.webp')->assertNotFound();
        $this->get('/storage/_img/w640/stores/../../.env.webp')->assertNotFound();
        $this->get('/storage/_img/w640/_img/w640/stores/1/a.png.webp.webp')->assertNotFound();
        $this->assertNull(app(ImageDerivatives::class)->url('https://evil.test/storage/stores/1/a.png', 640));
        $this->assertNull(app(ImageDerivatives::class)->url('https://api.sellchaze.test/storage/stores/1/anim.gif', 640));
    }

    public function test_storefront_products_get_a_webp_srcset_without_an_external_transformer(): void
    {
        $image = app(ResponsiveImageUrl::class)->for('https://api.sellchaze.test/storage/stores/12/p.jpg');
        $this->assertSame([320, 640, 960, 1280], $image['widths']);
        $this->assertStringContainsString('/storage/_img/w320/stores/12/p.jpg.webp 320w', (string) $image['srcset']);
        $this->assertStringEndsWith('/storage/_img/w1280/stores/12/p.jpg.webp', $image['src']);
    }
}
