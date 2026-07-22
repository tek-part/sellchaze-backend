<?php

namespace Tests\Feature;

use App\Services\Themes\SectionRegistry;
use Tests\TestCase;

class SectionRegistryTest extends TestCase
{
    private SectionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new SectionRegistry;
    }

    private function sectionsSchema(): array
    {
        return [
            'hero' => ['label' => 'Hero', 'settings' => [
                ['id' => 'headline', 'default' => 'Hi'],
                ['id' => 'show_tagline', 'default' => true],
            ]],
            'product-grid' => ['label' => 'Grid', 'settings' => []],
        ];
    }

    public function test_unknown_section_types_are_dropped(): void
    {
        $resolved = $this->registry->resolveSections($this->sectionsSchema(), [
            ['type' => 'hero'],
            ['type' => 'does-not-exist'],
            ['type' => 'product-grid'],
        ]);

        $this->assertCount(2, $resolved);
        $this->assertSame(['hero', 'product-grid'], array_column($resolved, 'type'));
    }

    public function test_section_settings_merge_defaults_with_overrides(): void
    {
        $resolved = $this->registry->resolveSections($this->sectionsSchema(), [
            ['type' => 'hero', 'settings' => ['show_tagline' => false]],
        ]);

        $this->assertSame('Hi', $resolved[0]['settings']['headline']);   // default
        $this->assertFalse($resolved[0]['settings']['show_tagline']);     // override
    }

    public function test_type_validation_helpers(): void
    {
        $this->assertTrue($this->registry->isValidType('hero', $this->sectionsSchema()));
        $this->assertFalse($this->registry->isValidType('nope', $this->sectionsSchema()));
        $this->assertSame(['hero', 'product-grid'], $this->registry->knownTypes($this->sectionsSchema()));
    }
}
