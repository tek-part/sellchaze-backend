<?php

namespace Tests\Unit;

use App\Services\Themes\CustomCssSanitizer;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomCssSanitizerTest extends TestCase
{
    public function test_it_scopes_every_selector_to_the_storefront_root(): void
    {
        $css = app(CustomCssSanitizer::class)->sanitize('.card, .product:hover { color: red; }');

        $this->assertSame('#storefront-root .card, #storefront-root .product:hover {color: red;}', $css);
    }

    #[DataProvider('dangerousCss')]
    public function test_it_rejects_active_or_external_css_constructs(string $css): void
    {
        $this->expectException(ValidationException::class);
        app(CustomCssSanitizer::class)->sanitize($css);
    }

    public static function dangerousCss(): array
    {
        return [
            ['@import "https://evil.example/a.css";'],
            ['.x { background: url(https://evil.example/a.png); }'],
            ['.x { width: expression(alert(1)); }'],
            ['</style><script>alert(1)</script>'],
        ];
    }
}
