<?php

namespace Tests\Unit;

use App\Services\Themes\SectionRegistry;
use PHPUnit\Framework\TestCase;

class ResponsiveSectionSettingsTest extends TestCase
{
    public function test_responsive_fields_emit_bounded_breakpoint_css_without_accepting_arbitrary_properties(): void
    {
        $schema = ['hero' => ['settings' => [
            ['id' => 'spacing', 'responsive' => true, 'css_property' => 'padding-block', 'default' => 80, 'min' => 0, 'max' => 200],
            ['id' => 'unsafe', 'responsive' => true, 'css_property' => 'background-image', 'default' => 10],
        ]]];
        $sections = [[
            'id' => 'section_1', 'type' => 'hero',
            'settings' => ['spacing' => 90, '__responsive' => ['spacing' => ['tablet' => 60, 'mobile' => 999]]],
        ]];

        $css = (new SectionRegistry)->responsiveCss($schema, $sections);

        $this->assertStringContainsString('[data-studio-section-id="section_1"]{padding-block:90px}', $css);
        $this->assertStringContainsString('@media(max-width:1023px){[data-studio-section-id="section_1"]{padding-block:60px}}', $css);
        $this->assertStringContainsString('@media(max-width:639px){[data-studio-section-id="section_1"]{padding-block:200px}}', $css);
        $this->assertStringNotContainsString('background-image', $css);
    }
}
