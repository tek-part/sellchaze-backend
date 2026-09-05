<?php

namespace Tests\Unit;

use App\Support\Localization\LocalizedValue;
use PHPUnit\Framework\TestCase;

class LocalizedValueTest extends TestCase
{
    public function test_is_localized_detects_locale_maps_only(): void
    {
        $this->assertTrue(LocalizedValue::isLocalized(['en' => 'Hello', 'ar' => 'مرحبا']));
        $this->assertTrue(LocalizedValue::isLocalized(['default' => 'Hello']));
        $this->assertTrue(LocalizedValue::isLocalized(['en' => null, 'ar' => 'x']));
        $this->assertFalse(LocalizedValue::isLocalized('Hello'));
        $this->assertFalse(LocalizedValue::isLocalized([]));
        $this->assertFalse(LocalizedValue::isLocalized(['Hello', 'World']));
        $this->assertFalse(LocalizedValue::isLocalized(['heading' => 'x']));
        $this->assertFalse(LocalizedValue::isLocalized(['en' => ['nested']]));
    }

    public function test_pick_follows_locale_then_fallback_then_default_then_first_non_empty(): void
    {
        $value = ['en' => 'Hello', 'ar' => 'مرحبا'];
        $this->assertSame('مرحبا', LocalizedValue::pick($value, 'ar', 'en'));
        $this->assertSame('Hello', LocalizedValue::pick($value, 'fr', 'en'));
        $this->assertSame('Hello', LocalizedValue::pick(['default' => 'Hello'], 'ar', 'en'));
        $this->assertSame('مرحبا', LocalizedValue::pick(['en' => '', 'ar' => 'مرحبا'], 'fr', 'en'));
        $this->assertSame('', LocalizedValue::pick(['en' => '', 'ar' => ' '], 'ar', 'en'));
        $this->assertSame('Plain', LocalizedValue::pick('Plain', 'ar', 'en'));
        $this->assertSame('', LocalizedValue::pick(null, 'ar', 'en'));
        $this->assertSame('Egypt', LocalizedValue::pick(['ar-EG' => 'Egypt'], 'ar', 'en'));
    }

    public function test_normalize_wraps_strings_and_drops_junk(): void
    {
        $this->assertSame(['en' => 'Hello'], LocalizedValue::normalize('  Hello ', 'en'));
        $this->assertSame([], LocalizedValue::normalize('', 'en'));
        $this->assertSame(
            ['en' => 'Hello', 'ar' => 'مرحبا'],
            LocalizedValue::normalize(['en' => 'Hello', 'ar' => 'مرحبا', 'xx-yy-zz' => 'bad', 'fr' => '', 0 => 'list'], 'en'),
        );
        $this->assertSame(['en' => 'Hello'], LocalizedValue::normalize(['en' => 'Hello', 'fr' => 'Bonjour'], 'en', ['en', 'ar']));
        $this->assertSame(['default' => 'X'], LocalizedValue::normalize(['default' => 'X'], 'en', ['en']));
    }

    public function test_completeness_reports_per_locale_presence(): void
    {
        $this->assertSame(['en' => true, 'ar' => false], LocalizedValue::completeness(['en' => 'Hello', 'ar' => ''], ['en', 'ar']));
        $this->assertSame(['en' => true, 'ar' => true], LocalizedValue::completeness('Plain', ['en', 'ar']));
        $this->assertSame(['en' => false, 'ar' => false], LocalizedValue::completeness(null, ['en', 'ar']));
    }

    public function test_direction_is_rtl_for_arabic_script_locales(): void
    {
        $this->assertSame('rtl', LocalizedValue::direction('ar'));
        $this->assertSame('rtl', LocalizedValue::direction('ar-EG'));
        $this->assertSame('ltr', LocalizedValue::direction('en'));
        $this->assertSame('ltr', LocalizedValue::direction(null));
    }
}
