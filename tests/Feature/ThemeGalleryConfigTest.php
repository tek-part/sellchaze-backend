<?php

namespace Tests\Feature;

use Tests\TestCase;

class ThemeGalleryConfigTest extends TestCase
{
    /** Category keys the dashboard gallery filters by (THEME_CATEGORIES in the frontend). */
    private const CATEGORIES = [
        'general', 'garden', 'digital', 'tools', 'handmade', 'food', 'pets', 'cars', 'books', 'hobbies', 'kids',
        'furniture', 'home', 'electronics', 'health', 'cosmetics', 'perfumes', 'watches', 'jewelry', 'shoes', 'fashion',
    ];

    public function test_every_bundled_theme_has_gallery_metadata_with_known_categories(): void
    {
        $gallery = config('theme_gallery');
        $keys = array_map(fn (string $path) => basename($path, '.json'), glob(resource_path('themes/storefront/*.json')));

        sort($keys);
        $configured = array_keys($gallery);
        sort($configured);
        $this->assertSame($keys, $configured);

        foreach ($gallery as $key => $entry) {
            $this->assertContains($entry['collection'], ['modern', 'classic'], $key);
            $this->assertNotEmpty($entry['categories'], $key);
            $this->assertSame([], array_values(array_diff($entry['categories'], self::CATEGORIES)), $key);
        }
    }
}
