<?php

namespace Tests\Unit;

use App\Services\StoreService;
use Tests\TestCase;

/**
 * Pure slug-hardening behaviour (no database).
 */
class StoreSlugTest extends TestCase
{
    public function test_reserved_words_are_pushed_out_of_the_reserved_namespace(): void
    {
        foreach (StoreService::RESERVED_SLUGS as $reserved) {
            $slug = StoreService::normalizeSlug($reserved);
            $this->assertNotContains($slug, StoreService::RESERVED_SLUGS, "Reserved '{$reserved}' still resolves to a reserved slug");
            $this->assertSame($reserved.'-store', $slug);
        }
    }

    public function test_arabic_name_produces_a_valid_latin_slug(): void
    {
        $slug = StoreService::normalizeSlug('متجر النور');

        $this->assertNotSame('', $slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9\-]+$/', $slug);
        $this->assertGreaterThanOrEqual(2, strlen($slug));
    }

    public function test_empty_or_symbol_only_input_falls_back_to_store(): void
    {
        $this->assertSame('store', StoreService::normalizeSlug('!!!'));
        $this->assertSame('store', StoreService::normalizeSlug('   '));
    }

    public function test_is_reserved_is_case_insensitive(): void
    {
        $this->assertTrue(StoreService::isReserved('ADMIN'));
        $this->assertTrue(StoreService::isReserved('api'));
        $this->assertFalse(StoreService::isReserved('nike'));
    }
}
