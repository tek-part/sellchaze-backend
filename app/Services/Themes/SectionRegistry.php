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
}
