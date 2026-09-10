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
                    is_array($sectionsSchema[$type]) ? $sectionsSchema[$type] : [],
                    is_array($section['settings'] ?? null) ? $section['settings'] : [],
                ),
            ];
        }

        return $resolved;
    }

    /**
     * Merge section-schema defaults with template/page overrides. Overrides are
     * coerced field-by-field (see sanitizeSectionSettings) so a stored layout can
     * never carry a value shape the theme runtime cannot render. Manifest-template
     * `blocks` are kept, each block's settings defaulted per its block type.
     *
     * @param  array  $sectionSchema  one `sections_schema` entry (`{settings, variants?, blocks?, style?}`)
     */
    public function resolveSectionSettings(array $sectionSchema, array $overrides): array
    {
        $settings = $this->defaultsFor($this->fieldList($sectionSchema));

        foreach ($this->sanitizeSectionSettings($sectionSchema, $overrides, true) as $key => $value) {
            $settings[$key] = $value; // template-level override
        }

        return $this->applyVariant($sectionSchema, $settings);
    }

    /**
     * Section-level sanitization (contract §7) on top of the field coercion of
     * sanitizeSettings():
     *  - `blocks`: kept only when the section schema declares `blocks`; each block is
     *    `{id, type, hidden, settings}` — unknown types are dropped, ids are generated
     *    when missing, settings are coerced against the block type's fields, per-type
     *    `limit` and the section `blocks.max` cap the list;
     *  - `__style`: only the shared style keys survive, type-coerced (see sanitizeStyle);
     *  - the `variants.field` value must be one of the declared options;
     *  - `__responsive` and any other undeclared key pass through untouched.
     *
     * @param  array  $sectionSchema  one `sections_schema` entry
     * @param  bool  $withDefaults  fill block-settings defaults per type (resolve mode)
     * @return array<string,mixed>
     */
    public function sanitizeSectionSettings(array $sectionSchema, array $values, bool $withDefaults = false): array
    {
        $plain = $values;
        unset($plain['blocks'], $plain['__style']);

        $out = $this->sanitizeSettings($this->fieldList($sectionSchema), $plain);

        $blocksSchema = is_array($sectionSchema['blocks'] ?? null) ? $sectionSchema['blocks'] : null;
        if (array_key_exists('blocks', $values) && $blocksSchema !== null) {
            $out['blocks'] = $this->sanitizeBlocks($blocksSchema, $values['blocks'], $withDefaults);
        }

        if (array_key_exists('__style', $values)) {
            $style = $this->sanitizeStyle($values['__style']);
            if ($style !== []) {
                $out['__style'] = $style;
            }
        }

        return $this->applyVariant($sectionSchema, $out);
    }

    /**
     * Coerce ONLY the provided keys against the schema (no defaults are added, so a
     * page's stored settings stay minimal). Tolerates the rich manifest shapes:
     *  - `list`: an array of item objects, capped to `max` items;
     *  - `select`: options as bare strings or `{value,label}` objects;
     *  - translatable text: a plain string or a `{locale: string}` map.
     * Keys the schema does not declare (e.g. `__responsive`) pass through untouched.
     *
     * @return array<string,mixed>
     */
    public function sanitizeSettings(array $schemaFields, array $values): array
    {
        $fields = [];
        foreach ($schemaFields as $field) {
            if (is_array($field) && isset($field['id'])) {
                $fields[(string) $field['id']] = $field;
            }
        }

        $out = [];
        foreach ($values as $key => $value) {
            $out[$key] = isset($fields[$key]) ? $this->coerceField($fields[$key], $value) : $value;
        }

        return $out;
    }

    /** Allowed `__style` enums (contract §7). */
    private const STYLE_ENUMS = [
        'background' => ['surface', 'primary', 'custom'],
        'container' => ['boxed', 'narrow', 'full'],
        'text_align' => ['start', 'center', 'end'],
    ];

    /**
     * The shared section style object: only the §7 keys survive, each coerced —
     * paddings are clamped to 0–200, enums must match, flags become booleans,
     * `anchor` is slugified (`[a-z0-9_-]`, ≤64), `css_class` keeps `[A-Za-z0-9 _-]`
     * (≤120), `background_color` a CSS colour token (≤64) and `background_image` a URL
     * without quotes/whitespace (≤2048). Everything else is dropped.
     *
     * @return array<string,mixed>
     */
    public function sanitizeStyle(mixed $style): array
    {
        if (! is_array($style)) {
            return [];
        }
        $out = [];
        foreach ($style as $key => $value) {
            switch ($key) {
                case 'padding_top':
                case 'padding_bottom':
                    if (is_numeric($value)) {
                        $out[$key] = max(0, min(200, $value + 0));
                    }
                    break;
                case 'background':
                case 'container':
                case 'text_align':
                    if (is_string($value) && in_array($value, self::STYLE_ENUMS[$key], true)) {
                        $out[$key] = $value;
                    }
                    break;
                case 'hide_mobile':
                case 'hide_desktop':
                    if (is_scalar($value)) {
                        $out[$key] = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    }
                    break;
                case 'anchor':
                    if (is_string($value)) {
                        $slug = (string) preg_replace('/[^a-z0-9\-_]/', '', (string) preg_replace('/\s+/', '-', strtolower(trim($value))));
                        $out[$key] = mb_substr($slug, 0, 64);
                    }
                    break;
                case 'css_class':
                    if (is_string($value)) {
                        $classes = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-zA-Z0-9 _\-]/', '', $value)));
                        $out[$key] = mb_substr($classes, 0, 120);
                    }
                    break;
                case 'background_color':
                    if (is_string($value) && preg_match('/^[a-zA-Z0-9#(),.% \-]{0,64}$/', $value)) {
                        $out[$key] = trim($value);
                    }
                    break;
                case 'background_image':
                    if (is_string($value) && preg_match("/^[^\\s\"'()<>;\\\\]{0,2048}$/u", $value)) {
                        $out[$key] = $value;
                    }
                    break;
                default:
                    break; // unknown style key: dropped
            }
        }

        return $out;
    }

    /**
     * @param  array  $blocksSchema  the section's `blocks` schema (`{types, min?, max?}`)
     * @return list<array<string,mixed>>
     */
    private function sanitizeBlocks(array $blocksSchema, mixed $blocks, bool $withDefaults): array
    {
        if (! is_array($blocks)) {
            return [];
        }
        $types = [];
        foreach (is_array($blocksSchema['types'] ?? null) ? $blocksSchema['types'] : [] as $blockType) {
            if (is_array($blockType) && isset($blockType['type']) && is_string($blockType['type'])) {
                $types[$blockType['type']] = $blockType;
            }
        }
        $max = isset($blocksSchema['max']) && is_numeric($blocksSchema['max']) ? max(0, (int) $blocksSchema['max']) : null;

        $out = [];
        $perType = [];
        foreach ($blocks as $block) {
            if (! is_array($block) || ! isset($block['type']) || ! is_string($block['type']) || ! isset($types[$block['type']])) {
                continue; // unknown / undeclared block type: dropped
            }
            $schema = $types[$block['type']];
            $limit = isset($schema['limit']) && is_numeric($schema['limit']) ? (int) $schema['limit'] : null;
            $perType[$block['type']] = ($perType[$block['type']] ?? 0) + 1;
            if ($limit !== null && $perType[$block['type']] > $limit) {
                continue; // over this block type's own limit
            }
            if ($max !== null && count($out) >= $max) {
                break; // section-wide cap
            }

            $fields = is_array($schema['settings'] ?? null) ? $schema['settings'] : [];
            $settings = is_array($block['settings'] ?? null) ? $block['settings'] : [];
            $sanitized = $this->sanitizeSettings($fields, $settings);
            if ($withDefaults) {
                $sanitized = [...$this->defaultsFor($fields), ...$sanitized];
            }

            $id = isset($block['id']) && is_scalar($block['id']) ? (string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $block['id']) : '';
            $hidden = $block['hidden'] ?? false;

            $extras = $block;
            unset($extras['id'], $extras['type'], $extras['hidden'], $extras['settings']);

            $out[] = [
                'id' => $id !== '' ? mb_substr($id, 0, 64) : 'b_'.uniqid(),
                'type' => $block['type'],
                'hidden' => is_bool($hidden) ? $hidden : (is_scalar($hidden) && filter_var($hidden, FILTER_VALIDATE_BOOLEAN)),
                'settings' => $sanitized,
                ...$extras, // undeclared block-level keys pass through (contract §7)
            ];
        }

        return $out;
    }

    /** Force the `variants.field` value into the declared options (first option / field default otherwise). */
    private function applyVariant(array $sectionSchema, array $settings): array
    {
        $variants = $sectionSchema['variants'] ?? null;
        if (! is_array($variants) || ! isset($variants['field']) || ! is_string($variants['field']) || ! array_key_exists($variants['field'], $settings)) {
            return $settings;
        }
        $allowed = SchemaOptions::values($variants['options'] ?? null);
        if ($allowed === [] || SchemaOptions::contains($variants['options'], $settings[$variants['field']])) {
            return $settings;
        }
        $default = null;
        foreach ($this->fieldList($sectionSchema) as $field) {
            if (is_array($field) && ($field['id'] ?? null) === $variants['field']) {
                $default = $field['default'] ?? null;
            }
        }
        $settings[$variants['field']] = SchemaOptions::contains($variants['options'], $default) ? (string) $default : $allowed[0];

        return $settings;
    }

    /** @return list<mixed> the section's `settings` field list */
    private function fieldList(array $sectionSchema): array
    {
        return is_array($sectionSchema['settings'] ?? null) ? array_values($sectionSchema['settings']) : [];
    }

    /** @return array<string,mixed> `{field id: default}` for a field list */
    private function defaultsFor(array $schemaFields): array
    {
        $defaults = [];
        foreach ($schemaFields as $field) {
            if (is_array($field) && isset($field['id'])) {
                $defaults[(string) $field['id']] = $field['default'] ?? null;
            }
        }

        return $defaults;
    }

    private function coerceField(array $field, mixed $value): mixed
    {
        $type = (string) ($field['type'] ?? 'text');
        $default = $field['default'] ?? null;

        switch ($type) {
            case 'list':
                if (! is_array($value)) {
                    return is_array($default) ? $default : [];
                }
                $items = array_values(array_filter($value, 'is_array'));
                $max = isset($field['max']) && is_numeric($field['max']) ? (int) $field['max'] : null;
                if ($max !== null && $max >= 0 && count($items) > $max) {
                    $items = array_slice($items, 0, $max);
                }
                if (is_array($field['item'] ?? null)) {
                    $items = array_map(fn (array $item) => $this->sanitizeSettings($field['item'], $item), $items);
                }

                return $items;

            case 'select':
                $allowed = SchemaOptions::values($field['options'] ?? null);
                if ($allowed === []) {
                    return $value; // no declared options: pass through
                }

                return SchemaOptions::contains($field['options'], $value) ? (string) $value : $default;

            case 'toggle':
                return is_bool($value) ? $value : (is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : (bool) $default);

            case 'number':
            case 'range':
                return is_numeric($value) ? $value + 0 : $default;

            case 'text':
            case 'textarea':
            case 'richtext':
                if (! empty($field['translatable'])) {
                    if (is_string($value)) {
                        return $value;
                    }
                    if (is_array($value)) {
                        $map = [];
                        foreach ($value as $locale => $text) {
                            if (is_string($locale) && $locale !== '' && (is_scalar($text) || $text === null)) {
                                $map[$locale] = (string) ($text ?? '');
                            }
                        }

                        return $map;
                    }

                    return $default;
                }

                return is_scalar($value) || $value === null ? $value : $default;

            default:
                return $value;
        }
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
