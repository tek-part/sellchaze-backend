<?php

namespace App\Models\Concerns;

use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocalizedValue;
use App\Support\Tenancy\CurrentStore;

/**
 * Per-attribute translations over the existing `translations` json column.
 *
 * Storage shape: `{ "name": {"en": "Air Max", "ar": "..."}, "description": {...} }`
 * — attribute keyed, then locale keyed. The base columns (`name`, `description`, …)
 * stay the canonical *default-locale* value so every legacy reader keeps working:
 * on save the default-locale translation is mirrored into the base column, and a
 * base column written without a translation seeds the default-locale entry.
 *
 * Models list their translatable attributes in `protected array $translatable`.
 */
trait HasTranslations
{
    public static function bootHasTranslations(): void
    {
        static::saving(function ($model) {
            $model->mirrorTranslations();
        });
    }

    /** @return list<string> */
    public function translatableAttributes(): array
    {
        return property_exists($this, 'translatable') ? $this->translatable : ['name', 'description'];
    }

    /** The value of `$attribute` for `$locale` (defaults to the request locale), falling back to the base column. */
    public function translated(string $attribute, ?string $locale = null): ?string
    {
        $context = app(LocaleContext::class);
        $locale ??= $context->current();
        $fallback = $this->translationDefaultLocale();

        $picked = LocalizedValue::pick($this->translationsFor($attribute), $locale, $fallback);
        if ($picked !== '') {
            return $picked;
        }

        $base = $this->getAttribute($attribute);

        return $base === null ? null : (string) $base;
    }

    /** @return array<string, string> `{locale: value}` for one attribute */
    public function translationsFor(string $attribute): array
    {
        $all = $this->translations;
        $map = is_array($all) ? ($all[$attribute] ?? null) : null;

        return is_array($map) ? $map : [];
    }

    /**
     * Replace the translations of one attribute (string → default-locale entry).
     *
     * @param  array<string, string|null>|string|null  $value
     */
    public function setTranslations(string $attribute, array|string|null $value): static
    {
        $all = is_array($this->translations) ? $this->translations : [];
        $map = LocalizedValue::normalize($value, $this->translationDefaultLocale());
        if ($map === []) {
            unset($all[$attribute]);
        } else {
            $all[$attribute] = $map;
        }
        $this->translations = $all === [] ? null : $all;

        return $this;
    }

    /**
     * Merge a `{attribute: {locale: value}}` payload (the API shape) into the model.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fillTranslations(array $payload): static
    {
        foreach ($this->translatableAttributes() as $attribute) {
            if (array_key_exists($attribute, $payload)) {
                $this->setTranslations($attribute, is_array($payload[$attribute]) || is_string($payload[$attribute]) ? $payload[$attribute] : null);
            }
        }

        return $this;
    }

    /**
     * The API/editor payload: every translatable attribute present as a `{locale: value}`
     * object (empty object when nothing is stored, so JSON clients always see `{}`).
     *
     * @return array<string, object|array<string, string>>
     */
    public function translationsPayload(): array
    {
        $out = [];
        foreach ($this->translatableAttributes() as $attribute) {
            $map = $this->translationsFor($attribute);
            $out[$attribute] = $map === [] ? new \stdClass : $map;
        }

        return $out;
    }

    /** The locale whose value lives in the base columns: the owning store's default, else the app locale. */
    public function translationDefaultLocale(): string
    {
        $store = $this->relationLoaded('store') ? $this->getRelation('store') : app(CurrentStore::class)->get();
        $default = $store?->default_locale;
        if (is_string($default) && trim($default) !== '') {
            return strtolower(trim($default));
        }
        $context = app(LocaleContext::class);

        return $context->has() ? $context->fallback() : (string) config('app.locale', 'en');
    }

    /**
     * Keep base columns and translations coherent before every save. Rules:
     *  1. A dirty `translations` payload is the source of truth: its default-locale
     *     entry is mirrored into the base column.
     *  2. A base column written on its own (legacy editors) seeds/overwrites the
     *     default-locale translation, so `translated()` never lags behind it.
     *  3. Untouched rows are left alone except for seeding a missing default entry.
     */
    public function mirrorTranslations(): void
    {
        $default = $this->translationDefaultLocale();
        $all = is_array($this->translations) ? $this->translations : [];
        $next = $all;
        $translationsDirty = $this->isDirty('translations');

        foreach ($this->translatableAttributes() as $attribute) {
            $map = LocalizedValue::normalize($all[$attribute] ?? null, $default);
            $base = $this->getAttribute($attribute);
            $base = is_scalar($base) ? trim((string) $base) : '';
            $stored = $map[$default] ?? '';

            if ($stored !== '' && ($translationsDirty || ! $this->isDirty($attribute))) {
                if ($stored !== $base) {
                    $this->setAttribute($attribute, $stored);
                }
            } elseif ($base !== '') {
                $map[$default] = $base;
            }

            if ($map === []) {
                unset($next[$attribute]);
            } else {
                $next[$attribute] = $map;
            }
        }

        if ($next !== $all) {
            $this->translations = $next === [] ? null : $next;
        }
    }
}
