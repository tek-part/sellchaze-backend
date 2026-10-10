<?php

namespace Tests\Unit;

use App\Support\ProductDescription;
use PHPUnit\Framework\TestCase;

class ProductDescriptionTest extends TestCase
{
    public function test_formatting_unicode_and_media_survive_without_executable_content(): void
    {
        $html = '<h2>تفاصيل المنتج ✨</h2><p><strong>متين</strong> <u>وأنيق</u></p><ul><li>قطن</li></ul>'
            .'<img src="https://example.test/photo.png" alt="حقيبة" onerror="alert(1)">'
            .'<video src="/storage/clip.mp4" autoplay onplay="alert(1)"></video>'
            .'<a href="https://example.test" target="_blank">More</a>';
        $clean = ProductDescription::clean($html);
        foreach (['<h2>', 'تفاصيل المنتج ✨', '<strong>متين</strong>', '<u>وأنيق</u>', '<li>قطن</li>', 'https://example.test/photo.png', '/storage/clip.mp4', 'controls', 'noopener noreferrer nofollow'] as $expected) {
            $this->assertStringContainsString($expected, $clean);
        }
        foreach (['onerror', 'onplay', 'autoplay'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $clean);
        }
        $this->assertSame($clean, ProductDescription::clean($clean));
    }

    public function test_scripts_url_obfuscation_svg_and_css_are_removed(): void
    {
        foreach (['<script>alert(1)</script>', '<svg><a href="javascript:alert(1)">x</a></svg>',
            '<a href="java&#x09;script:alert(1)">x</a>', '<img src="data:text/html,test">',
            '<video src="javascript:alert(1)"></video>', '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
            '<p style="position:fixed" onclick="alert(1)">Hi</p>',
            '<math><mtext><table><mglyph><style><!--</style><img title="--><img src=x onerror=alert(1)>">',
        ] as $attack) {
            $clean = ProductDescription::clean($attack);
            $this->assertDoesNotMatchRegularExpression('/<script|<svg|<math|<iframe|\s(?:on\w+|style|srcdoc)=|(?:href|src)="(?:javascript:|data:)/i', $clean);
        }
        $this->assertNull(ProductDescription::clean(null));
        $this->assertSame('', ProductDescription::clean(''));
        $this->assertSame('وصف عربي', ProductDescription::clean('وصف عربي'));
    }
}
