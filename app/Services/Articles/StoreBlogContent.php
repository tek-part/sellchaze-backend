<?php

namespace App\Services\Articles;

use App\Models\Store;
use App\Models\StoreArticle;
use App\Models\StoreContentPage;
use App\Support\Localization\LocaleContext;

/** Preserve existing blog URLs while exposing only explicitly published article snapshots. */
class StoreBlogContent
{
    public function legacyPosts(array $posts): array
    {
        $used = [];
        $result = [];
        foreach ($posts as $index => $post) {
            if (! is_array($post) || trim($post['title'] ?? '') === '') {
                continue;
            }
            $raw = trim($post['slug'] ?? '') ?: trim($post['title']);
            if (class_exists(\Normalizer::class)) {
                $raw = \Normalizer::normalize($raw, \Normalizer::FORM_KC) ?: $raw;
            }
            $base = mb_strtolower(trim(preg_replace('/[^\pL\pN_-]+/u', '-', $raw), '-')) ?: 'article-'.($index + 1);
            $slug = $base;
            $suffix = 2;
            while (isset($used[$slug])) {
                $slug = $base.'-'.$suffix++;
            }
            $used[$slug] = true;
            $result[] = [...$post, 'title' => trim($post['title']), 'slug' => $slug];
        }

        return $result;
    }

    public function reservedLegacySlugs(Store $store, ?array $payload = null): array
    {
        $payload ??= StoreContentPage::query()->where('store_id', $store->id)->where('key', 'blog')->value('data') ?? [];
        $slugs = [];
        foreach ($payload as $copy) {
            foreach ($this->legacyPosts(is_array($copy['posts'] ?? null) ? $copy['posts'] : []) as $post) {
                $slugs[] = $post['slug'];
            }
        }

        return array_values(array_unique($slugs));
    }

    public function publicContent(Store $store, ?StoreContentPage $legacy, ?StoreArticle $preview = null): array
    {
        if ($legacy && ! $legacy->is_published && ! $preview) {
            return ['data' => null, 'is_published' => false];
        }
        $articles = StoreArticle::query()->where('store_id', $store->id)->orderByDesc('id')->get(['id', 'slug', 'publication', 'publish_at', 'scheduled_publication', 'scheduled_at', 'archived', 'legacy_positions']);
        if (! $legacy && $articles->isEmpty()) {
            return ['data' => null, 'is_published' => null];
        }
        $hiddenBlog = $legacy && ! $legacy->is_published;
        $payload = $hiddenBlog ? [] : ($legacy?->data ?? []);
        $original = $payload;
        $default = LocaleContext::storeDefault($store);
        $locales = array_unique([...LocaleContext::storeSupported($store), ...array_keys($payload)]);
        foreach ($locales as $locale) {
            $copy = $original[$locale] ?? $original[$default] ?? (array_values($original)[0] ?? []);
            $posts = $this->legacyPosts(is_array($copy['posts'] ?? null) ? $copy['posts'] : []);
            $used = array_fill_keys(array_column($posts, 'slug'), true);
            $ordered = $articles->sortBy(fn (StoreArticle $article) => $article->legacy_positions[$locale] ?? PHP_INT_MAX);
            foreach ($ordered as $article) {
                $snapshot = $preview && $article->id === $preview->id ? $preview->draft : ($hiddenBlog ? null : $article->publicSnapshot());
                if ($snapshot === null || isset($used[$article->slug])) {
                    continue;
                }
                if ($article->legacy_positions !== null && ! array_key_exists($locale, $snapshot)) {
                    continue;
                }
                $translation = null;
                foreach (array_unique([$locale, $default, ...array_keys($snapshot)]) as $candidate) {
                    if (trim($snapshot[$candidate]['title'] ?? '') !== '') {
                        $translation = $snapshot[$candidate];
                        break;
                    }
                }
                if ($translation === null) {
                    continue;
                }
                $publishedAt = $article->scheduled_at !== null && ! $article->scheduled_at->isFuture() ? $article->scheduled_at : $article->publish_at;
                $posts[] = $article->legacy_positions !== null ? [...$translation, 'slug' => $article->slug] : [...$translation, 'slug' => $article->slug, 'date' => $publishedAt?->toDateString()];
                $used[$article->slug] = true;
            }
            $payload[$locale] = [...$copy, 'posts' => $posts];
        }

        return ['data' => $payload, 'is_published' => true, 'next_publish_at' => $articles->filter(fn (StoreArticle $article) => ! $article->archived && $article->scheduled_at?->isFuture())->min('scheduled_at')?->toIso8601String()];
    }
}
