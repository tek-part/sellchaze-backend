<?php

namespace App\Services\Themes;

/**
 * Server-side section registry. It validates a template's sections against the
 * active theme's sections_schema and resolves each into a safe, fully-defaulted
 * definition. Unknown section types are dropped (graceful degradation) — a theme
 * can never reference or render a section type it did not declare. The React SSR
 * runtime holds the matching type -> component map; this layer guarantees only
 * declared, schema-valid section definitions ever reach it.
 */
class SectionRegistry
{
    /** @return string[] declared section types */
    public function knownTypes(array $sectionsSchema): array
    {
        return array_keys($sectionsSchema);
    }

    public function isValidType(string $type, array $sectionsSchema): bool
    {
        return array_key_exists($type, $sectionsSchema);
    }

    /**
     * Resolve a template's section list into validated definitions.
     *
     * @param  array  $sectionsSchema  the theme version's sections_schema
     * @param  array  $templateSections  [{ type, settings? }, ...]
     * @return array<int,array{type:string,settings:array}>
     */
    public function resolveSections(array $sectionsSchema, array $templateSections): array
    {
        $resolved = [];
        foreach ($templateSections as $section) {
            $type = $section['type'] ?? null;
            if ($type === null || ! $this->isValidType($type, $sectionsSchema)) {
                continue; // graceful degradation: skip unknown/undeclared sections
            }

            $resolved[] = [
                'id' => isset($section['id']) ? (string) $section['id'] : null,
                'type' => $type,
                'settings' => $this->resolveSectionSettings(
                    $sectionsSchema[$type]['settings'] ?? [],
                    $section['settings'] ?? [],
                ),
            ];
        }

        return $resolved;
    }

    /** Merge section-schema defaults with template overrides. */
    private function resolveSectionSettings(array $schemaFields, array $overrides): array
    {
        $settings = [];
        foreach ($schemaFields as $field) {
            if (isset($field['id'])) {
                $settings[$field['id']] = $field['default'] ?? null;
            }
        }

        foreach ($overrides as $key => $value) {
            $settings[$key] = $value; // template-level override
        }

        return $settings;
    }

    public function responsiveCss(array $sectionsSchema, array $sections): string
    {
        $rules = ['desktop' => [], 'tablet' => [], 'mobile' => []];
        $allowedProperties = ['padding-block', 'padding-inline', 'margin-block', 'gap', 'font-size'];
        foreach ($sections as $section) {
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($section['id'] ?? ''));
            $type = (string) ($section['type'] ?? '');
            if ($id === '' || ! isset($sectionsSchema[$type])) {
                continue;
            }
            $settings = $section['settings'] ?? [];
            $responsive = is_array($settings['__responsive'] ?? null) ? $settings['__responsive'] : [];
            foreach ($sectionsSchema[$type]['settings'] ?? [] as $field) {
                $fieldId = (string) ($field['id'] ?? '');
                $property = (string) ($field['css_property'] ?? '');
                if (! ($field['responsive'] ?? false) || ! in_array($property, $allowedProperties, true)) {
                    continue;
                }
                foreach (['desktop', 'tablet', 'mobile'] as $viewport) {
                    $value = $viewport === 'desktop' ? ($settings[$fieldId] ?? $field['default'] ?? null) : ($responsive[$fieldId][$viewport] ?? null);
                    if (! is_numeric($value)) {
                        continue;
                    }
                    $minimum = isset($field['min']) ? (float) $field['min'] : 0;
                    $maximum = isset($field['max']) ? (float) $field['max'] : 500;
                    $safe = max($minimum, min($maximum, (float) $value));
                    $formatted = number_format($safe, 3, '.', '');
                    $formatted = rtrim(rtrim($formatted, '0'), '.');
                    $rules[$viewport][] = sprintf('[data-studio-section-id="%s"]{%s:%spx}', $id, $property, $formatted === '' ? '0' : $formatted);
                }
            }
        }

        $css = implode('', $rules['desktop']);
        if ($rules['tablet']) {
            $css .= '@media(max-width:1023px){'.implode('', $rules['tablet']).'}';
        }
        if ($rules['mobile']) {
            $css .= '@media(max-width:639px){'.implode('', $rules['mobile']).'}';
        }

        return $css;
    }
}
