<?php

namespace Tests\Feature;

use App\Services\Themes\ThemeSettingsValidator;
use Tests\TestCase;

class ThemeSettingsValidationTest extends TestCase
{
    private ThemeSettingsValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ThemeSettingsValidator;
    }

    private function schema(): array
    {
        return [
            ['id' => 'colors', 'label' => 'Colors', 'fields' => [
                ['id' => 'primary', 'type' => 'color', 'default' => '#2563eb'],
            ]],
            ['id' => 'layout', 'label' => 'Layout', 'fields' => [
                ['id' => 'per_row', 'type' => 'range', 'default' => 4, 'min' => 2, 'max' => 6],
                ['id' => 'show', 'type' => 'toggle', 'default' => true],
                ['id' => 'font', 'type' => 'select', 'default' => 'system', 'options' => ['system', 'serif']],
            ]],
        ];
    }

    public function test_missing_settings_fall_back_to_defaults(): void
    {
        $out = $this->validator->coerce([], $this->schema());

        $this->assertSame('#2563eb', $out['primary']);
        $this->assertSame(4, $out['per_row']);
        $this->assertTrue($out['show']);
        $this->assertSame('system', $out['font']);
    }

    public function test_invalid_values_are_coerced_to_safe_values(): void
    {
        $out = $this->validator->coerce([
            'per_row' => 99,          // clamped to max 6
            'font' => 'comic',        // not an option -> default
            'primary' => 123,         // not a string -> default
        ], $this->schema());

        $this->assertSame(6, $out['per_row']);
        $this->assertSame('system', $out['font']);
        $this->assertSame('#2563eb', $out['primary']);
    }

    public function test_strict_errors_flag_type_mismatches(): void
    {
        $errors = $this->validator->errors(['per_row' => 'lots', 'show' => 'yes'], $this->schema());

        $this->assertNotEmpty($errors);
    }

    public function test_valid_settings_have_no_strict_errors(): void
    {
        $this->assertSame([], $this->validator->errors(['per_row' => 4, 'show' => true, 'font' => 'serif'], $this->schema()));
    }
}
