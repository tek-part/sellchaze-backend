<?php

namespace App\Support\StoreContent;

/**
 * The fixed set of editable storefront "system" pages and the typed field schema
 * for each. Single source of truth shared by the dashboard editor, the write API
 * and the storefront (which merges the payload over the theme defaults).
 *
 * `label`/`placeholder` are i18n KEYS resolved on the client (see i18n.js) so the
 * editor is fully translatable. Field types:
 *   text | textarea | richtext | image | url | toggle | lines
 *   (lines = textarea edited one-item-per-line, stored as string[])
 *   | repeater (ordered list of objects; `item` lists the sub-fields).
 */
class ContentPageSchema
{
    /** Field types whose value is copy the merchant writes per locale (`translatable: true`). */
    public const TRANSLATABLE_TYPES = ['text', 'textarea', 'richtext', 'lines'];

    /**
     * Every page definition with `translatable` flags resolved on each field (and
     * repeater sub-field). The storage shape is one full copy per locale
     * (`{en: {...}, ar: {...}}`), so the flag tells the editor which fields need a
     * per-locale value and which (images, urls, toggles) are shared copy-overs.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $pages = self::definitions();
        foreach ($pages as &$page) {
            $page['fields'] = self::flagFields($page['fields']);
        }

        return $pages;
    }

    /** @param  array<int, array<string, mixed>>  $fields */
    private static function flagFields(array $fields): array
    {
        foreach ($fields as &$field) {
            $field['translatable'] = in_array($field['type'] ?? 'text', self::TRANSLATABLE_TYPES, true);
            if (($field['type'] ?? null) === 'repeater' && is_array($field['item'] ?? null)) {
                $field['item'] = self::flagFields($field['item']);
            }
        }

        return $fields;
    }

    /** @return array<string, array<string, mixed>> */
    private static function definitions(): array
    {
        return [
            'home' => [
                'label' => 'cp_home',
                'icon' => 'home',
                'path' => '/',
                'fields' => [
                    // Hero (single banner)
                    ['key' => 'hero_eyebrow', 'type' => 'text', 'label' => 'cpf_hero_eyebrow'],
                    ['key' => 'hero_heading', 'type' => 'text', 'label' => 'cpf_hero_heading'],
                    ['key' => 'hero_subheading', 'type' => 'textarea', 'label' => 'cpf_hero_subheading'],
                    ['key' => 'hero_cta_label', 'type' => 'text', 'label' => 'cpf_hero_cta_label'],
                    ['key' => 'hero_cta_url', 'type' => 'text', 'label' => 'cpf_hero_cta_url'],
                    ['key' => 'hero_cta2_label', 'type' => 'text', 'label' => 'cpf_hero_cta2_label'],
                    ['key' => 'hero_cta2_url', 'type' => 'text', 'label' => 'cpf_hero_cta2_url'],
                    ['key' => 'hero_image', 'type' => 'image', 'label' => 'cpf_hero_image'],
                    // Hero slider (multiple rotating slides)
                    ['key' => 'slides', 'type' => 'repeater', 'label' => 'cpf_slides', 'item' => [
                        ['key' => 'eyebrow', 'type' => 'text', 'label' => 'cpf_eyebrow'],
                        ['key' => 'heading', 'type' => 'text', 'label' => 'cpf_heading'],
                        ['key' => 'subheading', 'type' => 'text', 'label' => 'cpf_subheading'],
                        ['key' => 'cta_label', 'type' => 'text', 'label' => 'cpf_cta_label'],
                        ['key' => 'cta_url', 'type' => 'text', 'label' => 'cpf_cta_url'],
                        ['key' => 'image', 'type' => 'image', 'label' => 'cpf_image'],
                    ]],
                    // Why-choose-us / value props
                    ['key' => 'why_choose_us', 'type' => 'repeater', 'label' => 'cpf_why_choose_us', 'item' => [
                        ['key' => 'title', 'type' => 'text', 'label' => 'cpf_title'],
                        ['key' => 'text', 'type' => 'textarea', 'label' => 'cpf_text'],
                    ]],
                    // Editorial banner
                    ['key' => 'editorial_eyebrow', 'type' => 'text', 'label' => 'cpf_editorial_eyebrow'],
                    ['key' => 'editorial_heading', 'type' => 'text', 'label' => 'cpf_editorial_heading'],
                    ['key' => 'editorial_body', 'type' => 'textarea', 'label' => 'cpf_editorial_body'],
                    ['key' => 'editorial_cta_label', 'type' => 'text', 'label' => 'cpf_editorial_cta_label'],
                    ['key' => 'editorial_cta_url', 'type' => 'text', 'label' => 'cpf_editorial_cta_url'],
                    ['key' => 'editorial_image', 'type' => 'image', 'label' => 'cpf_editorial_image'],
                    // UGC / social gallery
                    ['key' => 'ugc_handle', 'type' => 'text', 'label' => 'cpf_ugc_handle'],
                    ['key' => 'ugc', 'type' => 'repeater', 'label' => 'cpf_ugc', 'item' => [
                        ['key' => 'image', 'type' => 'image', 'label' => 'cpf_image'],
                        ['key' => 'url', 'type' => 'text', 'label' => 'cpf_url'],
                    ]],
                    // Newsletter copy
                    ['key' => 'newsletter_title', 'type' => 'text', 'label' => 'cpf_newsletter_title'],
                    ['key' => 'newsletter_text', 'type' => 'textarea', 'label' => 'cpf_newsletter_text'],
                    ['key' => 'newsletter_cta', 'type' => 'text', 'label' => 'cpf_newsletter_cta'],
                    // Editable section titles
                    ['key' => 'collections_heading', 'type' => 'text', 'label' => 'cpf_collections_heading', 'group' => 'titles'],
                    ['key' => 'brands_heading', 'type' => 'text', 'label' => 'cpf_brands_heading', 'group' => 'titles'],
                    ['key' => 'why_heading', 'type' => 'text', 'label' => 'cpf_why_heading', 'group' => 'titles'],
                    ['key' => 'ugc_heading', 'type' => 'text', 'label' => 'cpf_ugc_heading', 'group' => 'titles'],
                ],
            ],
            'about' => [
                'label' => 'cp_about',
                'icon' => 'info',
                'path' => '/about',
                'fields' => [
                    ['key' => 'heading', 'type' => 'text', 'label' => 'cpf_heading', 'placeholder' => 'cpp_about_heading'],
                    ['key' => 'subheading', 'type' => 'text', 'label' => 'cpf_subheading'],
                    ['key' => 'hero_image', 'type' => 'image', 'label' => 'cpf_hero_image'],
                    ['key' => 'mission', 'type' => 'textarea', 'label' => 'cpf_mission'],
                    ['key' => 'vision', 'type' => 'textarea', 'label' => 'cpf_vision'],
                    ['key' => 'story', 'type' => 'lines', 'label' => 'cpf_story', 'placeholder' => 'cpp_one_per_line'],
                    ['key' => 'values', 'type' => 'repeater', 'label' => 'cpf_values', 'item' => [
                        ['key' => 'title', 'type' => 'text', 'label' => 'cpf_title'],
                        ['key' => 'body', 'type' => 'textarea', 'label' => 'cpf_body'],
                    ]],
                    ['key' => 'stats', 'type' => 'repeater', 'label' => 'cpf_stats', 'item' => [
                        ['key' => 'value', 'type' => 'text', 'label' => 'cpf_value'],
                        ['key' => 'label', 'type' => 'text', 'label' => 'cpf_label'],
                    ]],
                    ['key' => 'founded', 'type' => 'text', 'label' => 'cpf_founded'],
                    ['key' => 'story_image', 'type' => 'image', 'label' => 'cpf_story_image'],
                    ['key' => 'craft_image', 'type' => 'image', 'label' => 'cpf_craft_image'],
                    ['key' => 'craft_title', 'type' => 'text', 'label' => 'cpf_craft_title'],
                    ['key' => 'craft_body', 'type' => 'lines', 'label' => 'cpf_craft_body', 'placeholder' => 'cpp_one_per_line'],
                    ['key' => 'milestones', 'type' => 'repeater', 'label' => 'cpf_milestones', 'item' => [
                        ['key' => 'year', 'type' => 'text', 'label' => 'cpf_year'],
                        ['key' => 'title', 'type' => 'text', 'label' => 'cpf_title'],
                        ['key' => 'body', 'type' => 'textarea', 'label' => 'cpf_body'],
                    ]],
                    ['key' => 'leadership', 'type' => 'repeater', 'label' => 'cpf_leadership', 'item' => [
                        ['key' => 'name', 'type' => 'text', 'label' => 'cpf_name'],
                        ['key' => 'role', 'type' => 'text', 'label' => 'cpf_role'],
                        ['key' => 'bio', 'type' => 'textarea', 'label' => 'cpf_bio'],
                        ['key' => 'photo', 'type' => 'image', 'label' => 'cpf_photo'],
                    ]],
                    ['key' => 'awards', 'type' => 'repeater', 'label' => 'cpf_awards', 'item' => [
                        ['key' => 'year', 'type' => 'text', 'label' => 'cpf_year'],
                        ['key' => 'title', 'type' => 'text', 'label' => 'cpf_title'],
                        ['key' => 'body', 'type' => 'textarea', 'label' => 'cpf_body'],
                    ]],
                    ['key' => 'partners', 'type' => 'lines', 'label' => 'cpf_partners', 'placeholder' => 'cpp_one_per_line'],
                    ['key' => 'testimonials', 'type' => 'repeater', 'label' => 'cpf_testimonials', 'item' => [
                        ['key' => 'quote', 'type' => 'textarea', 'label' => 'cpf_quote'],
                        ['key' => 'author', 'type' => 'text', 'label' => 'cpf_author'],
                        ['key' => 'detail', 'type' => 'text', 'label' => 'cpf_detail'],
                    ]],
                    // Section titles (eyebrows/headings) — editable design labels.
                    ['key' => 'story_eyebrow', 'type' => 'text', 'label' => 'cpf_story_eyebrow', 'group' => 'titles'],
                    ['key' => 'story_heading', 'type' => 'text', 'label' => 'cpf_story_heading', 'group' => 'titles'],
                    ['key' => 'values_eyebrow', 'type' => 'text', 'label' => 'cpf_values_eyebrow', 'group' => 'titles'],
                    ['key' => 'values_heading', 'type' => 'text', 'label' => 'cpf_values_heading', 'group' => 'titles'],
                    ['key' => 'milestones_eyebrow', 'type' => 'text', 'label' => 'cpf_milestones_eyebrow', 'group' => 'titles'],
                    ['key' => 'milestones_heading', 'type' => 'text', 'label' => 'cpf_milestones_heading', 'group' => 'titles'],
                    ['key' => 'leadership_eyebrow', 'type' => 'text', 'label' => 'cpf_leadership_eyebrow', 'group' => 'titles'],
                    ['key' => 'leadership_heading', 'type' => 'text', 'label' => 'cpf_leadership_heading', 'group' => 'titles'],
                    ['key' => 'craft_eyebrow', 'type' => 'text', 'label' => 'cpf_craft_eyebrow', 'group' => 'titles'],
                    ['key' => 'awards_eyebrow', 'type' => 'text', 'label' => 'cpf_awards_eyebrow', 'group' => 'titles'],
                    ['key' => 'awards_heading', 'type' => 'text', 'label' => 'cpf_awards_heading', 'group' => 'titles'],
                    ['key' => 'testimonials_eyebrow', 'type' => 'text', 'label' => 'cpf_testimonials_eyebrow', 'group' => 'titles'],
                    ['key' => 'testimonials_heading', 'type' => 'text', 'label' => 'cpf_testimonials_heading', 'group' => 'titles'],
                    ['key' => 'partners_eyebrow', 'type' => 'text', 'label' => 'cpf_partners_eyebrow', 'group' => 'titles'],
                    ['key' => 'partners_heading', 'type' => 'text', 'label' => 'cpf_partners_heading', 'group' => 'titles'],
                    ['key' => 'cta_title', 'type' => 'text', 'label' => 'cpf_cta_title', 'group' => 'titles'],
                    ['key' => 'cta_text', 'type' => 'textarea', 'label' => 'cpf_cta_text', 'group' => 'titles'],
                ],
            ],
            'contact' => [
                'label' => 'cp_contact',
                'icon' => 'phone',
                'path' => '/contact',
                'fields' => [
                    ['key' => 'heading', 'type' => 'text', 'label' => 'cpf_heading'],
                    ['key' => 'intro', 'type' => 'textarea', 'label' => 'cpf_intro'],
                    ['key' => 'email', 'type' => 'text', 'label' => 'cpf_email'],
                    ['key' => 'phone', 'type' => 'text', 'label' => 'cpf_phone'],
                    ['key' => 'address', 'type' => 'textarea', 'label' => 'cpf_address'],
                    ['key' => 'hours', 'type' => 'text', 'label' => 'cpf_hours'],
                    ['key' => 'map_embed', 'type' => 'url', 'label' => 'cpf_map_embed', 'placeholder' => 'cpp_url'],
                    ['key' => 'show_form', 'type' => 'toggle', 'label' => 'cpf_show_form'],
                    ['key' => 'notice_title', 'type' => 'text', 'label' => 'cpf_notice_title'],
                    ['key' => 'notice_body', 'type' => 'textarea', 'label' => 'cpf_notice_body'],
                    ['key' => 'departments', 'type' => 'repeater', 'label' => 'cpf_departments', 'item' => [
                        ['key' => 'name', 'type' => 'text', 'label' => 'cpf_name'],
                        ['key' => 'description', 'type' => 'textarea', 'label' => 'cpf_description'],
                        ['key' => 'email', 'type' => 'text', 'label' => 'cpf_email'],
                        ['key' => 'phone', 'type' => 'text', 'label' => 'cpf_phone'],
                        ['key' => 'hours', 'type' => 'text', 'label' => 'cpf_hours'],
                    ]],
                    ['key' => 'locations', 'type' => 'repeater', 'label' => 'cpf_locations', 'item' => [
                        ['key' => 'name', 'type' => 'text', 'label' => 'cpf_name'],
                        ['key' => 'address', 'type' => 'text', 'label' => 'cpf_address'],
                        ['key' => 'city', 'type' => 'text', 'label' => 'cpf_city'],
                        ['key' => 'country', 'type' => 'text', 'label' => 'cpf_country'],
                        ['key' => 'phone', 'type' => 'text', 'label' => 'cpf_phone'],
                        ['key' => 'hours', 'type' => 'text', 'label' => 'cpf_hours'],
                        ['key' => 'lat', 'type' => 'text', 'label' => 'cpf_lat'],
                        ['key' => 'lon', 'type' => 'text', 'label' => 'cpf_lon'],
                    ]],
                    // Section titles — editable design labels.
                    ['key' => 'hero_eyebrow', 'type' => 'text', 'label' => 'cpf_hero_eyebrow', 'group' => 'titles'],
                    ['key' => 'departments_eyebrow', 'type' => 'text', 'label' => 'cpf_departments_eyebrow', 'group' => 'titles'],
                    ['key' => 'departments_heading', 'type' => 'text', 'label' => 'cpf_departments_heading', 'group' => 'titles'],
                    ['key' => 'form_heading', 'type' => 'text', 'label' => 'cpf_form_heading', 'group' => 'titles'],
                    ['key' => 'locations_heading', 'type' => 'text', 'label' => 'cpf_locations_heading', 'group' => 'titles'],
                    ['key' => 'faq_eyebrow', 'type' => 'text', 'label' => 'cpf_faq_eyebrow', 'group' => 'titles'],
                    ['key' => 'faq_heading', 'type' => 'text', 'label' => 'cpf_faq_heading', 'group' => 'titles'],
                ],
            ],
            'faq' => [
                'label' => 'cp_faq',
                'icon' => 'question',
                'path' => '/faq',
                'fields' => [
                    ['key' => 'heading', 'type' => 'text', 'label' => 'cpf_heading'],
                    ['key' => 'items', 'type' => 'repeater', 'label' => 'cpf_items', 'item' => [
                        ['key' => 'question', 'type' => 'text', 'label' => 'cpf_question'],
                        ['key' => 'answer', 'type' => 'textarea', 'label' => 'cpf_answer'],
                    ]],
                ],
            ],
            'shipping' => self::policyFields('cp_shipping', '/pages/shipping'),
            'returns' => self::policyFields('cp_returns', '/pages/returns'),
            'blog' => [
                'label' => 'cp_blog',
                'icon' => 'blog',
                'path' => '/blog',
                'fields' => [
                    ['key' => 'heading', 'type' => 'text', 'label' => 'cpf_heading'],
                    ['key' => 'intro', 'type' => 'textarea', 'label' => 'cpf_intro'],
                    ['key' => 'posts', 'type' => 'repeater', 'label' => 'cpf_posts', 'item' => [
                        ['key' => 'title', 'type' => 'text', 'label' => 'cpf_title'],
                        ['key' => 'slug', 'type' => 'text', 'label' => 'cpf_slug'],
                        ['key' => 'excerpt', 'type' => 'textarea', 'label' => 'cpf_excerpt'],
                        ['key' => 'image', 'type' => 'image', 'label' => 'cpf_image'],
                        ['key' => 'date', 'type' => 'date', 'label' => 'cpf_date'],
                        ['key' => 'body', 'type' => 'richtext', 'label' => 'cpf_body'],
                    ]],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function policyFields(string $label, string $path): array
    {
        return [
            'label' => $label,
            'icon' => 'doc',
            'path' => $path,
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'cpf_title'],
                ['key' => 'summary', 'type' => 'textarea', 'label' => 'cpf_summary'],
                ['key' => 'sections', 'type' => 'repeater', 'label' => 'cpf_sections', 'item' => [
                    ['key' => 'heading', 'type' => 'text', 'label' => 'cpf_heading'],
                    ['key' => 'body', 'type' => 'lines', 'label' => 'cpf_body', 'placeholder' => 'cpp_one_per_line'],
                    ['key' => 'list', 'type' => 'lines', 'label' => 'cpf_list', 'placeholder' => 'cpp_one_per_line'],
                ]],
            ],
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function fields(string $key): array
    {
        return self::all()[$key]['fields'] ?? [];
    }
}
