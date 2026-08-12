<?php

namespace Tests\Unit;

use App\Services\Storefront\ResponsiveImageUrl;
use Tests\TestCase;

class ResponsiveImageUrlTest extends TestCase
{
    public function test_it_builds_sorted_signed_provider_neutral_srcsets(): void
    {
        config()->set('sellchase.storefront.images.transformer_url', 'https://images.sellchaze.test/transform');
        config()->set('sellchase.storefront.images.signing_secret', 'test-secret');
        config()->set('sellchase.storefront.images.quality', 80);

        $image = app(ResponsiveImageUrl::class)->for('https://assets.test/product one.jpg', [960, 320, 960]);

        $this->assertSame([320, 960], $image['widths']);
        $this->assertStringContainsString('width=960', $image['src']);
        $this->assertStringContainsString('quality=80', $image['src']);
        $this->assertStringContainsString('signature=', $image['src']);
        $this->assertStringContainsString(' 320w, ', $image['srcset']);
        $this->assertStringEndsWith(' 960w', $image['srcset']);
    }

    public function test_it_falls_back_to_the_original_when_no_transformer_is_configured(): void
    {
        config()->set('sellchase.storefront.images.transformer_url', null);
        $image = app(ResponsiveImageUrl::class)->for('https://assets.test/original.jpg');

        $this->assertSame('https://assets.test/original.jpg', $image['src']);
        $this->assertNull($image['srcset']);
    }
}
