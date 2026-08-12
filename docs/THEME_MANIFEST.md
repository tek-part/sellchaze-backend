# Theme Manifest (`theme.json`) Specification — Phase 4A

Every theme ships a `theme.json` manifest. It is the **machine contract** the
platform ingests at registration time; it lets the platform build settings UIs,
previews, and page-builder tooling **without executing theme code**. Theme *code*
(React bundles) is versioned separately and is not part of Phase 4A.

Location (first-party): `resources/themes/{key}/theme.json`.

## Top-level fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `key` | string | ✅ | Unique slug, `^[a-z0-9-]+$`. Immutable identity. |
| `name` | string | ✅ | Display name. |
| `version` | string | ✅ | Semver `MAJOR.MINOR.PATCH`. Immutable once published. |
| `author` | string | — | e.g. `Sellchaze`. |
| `category` | string | — | Grouping for the (future) marketplace. |
| `min_platform_version` | string | — | Semver compatibility floor. |
| `preview_image` | string\|null | — | Preview asset URL. |
| `settings_schema` | array | ✅ | Global settings groups (below). |
| `sections_schema` | object | ✅ | Available section types + their prop schemas. |
| `templates` | object | ✅ | Default section layout per template. |

## `settings_schema`
An array of **groups**; each group has `id`, `label`, and `fields[]`.
A field is `{ id, type, label, default, options?, min?, max? }`.

Supported field `type`s: `color`, `text`, `select`, `toggle`, `number`, `range`,
`image`, `url`, `richtext`. `default` seeds a store's settings on install; new
fields added in later versions are backfilled from their `default` (upgrade safety).

## `sections_schema`
A map of `sectionType -> { label, settings[] }`, where `settings[]` uses the same
field shape as above. Section types are the building blocks referenced by templates
and (Phase 4B+) the page builder.

**Shared core section types** (recommended for portability across themes):
`hero`, `category-list`, `product-grid`, `category-header`, `product-details`,
`rich-text`. Pages built from shared types survive theme switches; theme-specific
sections degrade gracefully when absent in the new theme.

## `templates`
A map of `templateName -> { sections: [ { type, settings? } ] }`.
**Required templates (Phase 4A):** `home`, `product`, `category`. Every `type`
referenced by a template **must** exist in `sections_schema` (enforced at validation).
`settings` on a template section override the section's defaults for that template.

## Validation rules (enforced by `ThemeRegistry`)
1. `key`, `name`, `version`, `settings_schema`, `sections_schema`, `templates` are present.
2. `key` matches `^[a-z0-9-]+$`; `version` is valid semver.
3. `home`, `product`, `category` templates exist.
4. Every template section has a `type` that exists in `sections_schema`.

A manifest that fails any rule is rejected; a valid manifest upserts a `themes`
row and an immutable `theme_versions` row, and updates `themes.latest_version_id`.

## Store integration
Installing a theme creates a `store_themes` row with `settings` seeded from
`settings_schema` defaults. Activating it flips the active install and mirrors
`theme_id` + resolved `settings` onto `stores.theme_id` / `stores.theme_settings`
(the fast-read cache consumed by SSR in Phase 4B).
