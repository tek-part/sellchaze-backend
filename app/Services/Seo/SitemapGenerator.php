<?php

namespace App\Services\Seo;

use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Builds the public sitemap from live data and notifies search engines.
 *
 * The SPA ships a build-time sitemap, which cannot know about suppliers who
 * register after the last deploy. This regenerates the file from the database —
 * directory, sectors, specialties, city pages and supplier profiles — and pings
 * Google so a new supplier's page gets crawled without waiting for a rebuild.
 *
 * Newest suppliers are listed first so the most recently added pages are the
 * first thing a crawler sees.
 */
class SitemapGenerator
{
    /** Static, always-present routes. */
    private const STATIC_PATHS = [
        ['/', '1.0', 'daily'],
        ['/suppliers', '0.9', 'daily'],
        ['/pricing', '0.5', 'monthly'],
        ['/features', '0.5', 'monthly'],
        ['/about', '0.4', 'monthly'],
        ['/contact', '0.4', 'monthly'],
        ['/terms', '0.3', 'yearly'],
        ['/privacy', '0.3', 'yearly'],
    ];

    public function siteUrl(): string
    {
        return rtrim((string) config('sellchase.frontend_url', 'https://sellchaze.com'), '/');
    }

    /** Absolute path the sitemap is written to, or null when not configured. */
    public function outputPath(): ?string
    {
        $path = config('sellchase.sitemap_path');

        return $path ? (string) $path : null;
    }

    /**
     * Regenerate the sitemap file. Returns the number of URLs written, or null
     * when no output path is configured (nothing to do).
     */
    public function generate(): ?int
    {
        $path = $this->outputPath();
        if (! $path) {
            return null;
        }

        $urls = $this->urls();
        $xml = $this->render($urls);

        $dir = dirname($path);
        if (! is_dir($dir)) {
            return null;
        }

        // Write atomically so a crawler never reads a half-written file.
        $tmp = $path.'.tmp';
        file_put_contents($tmp, $xml);
        rename($tmp, $path);

        return count($urls);
    }

    /**
     * Every public URL worth indexing, newest suppliers first.
     *
     * @return array<int, array{loc: string, priority: string, changefreq: string, lastmod?: string}>
     */
    public function urls(): array
    {
        $base = $this->siteUrl();
        $urls = [];

        foreach (self::STATIC_PATHS as [$path, $priority, $freq]) {
            $urls[] = ['loc' => $base.$path, 'priority' => $priority, 'changefreq' => $freq];
        }

        // Supplier profiles — newest first, so fresh pages head the file.
        $suppliers = User::query()
            ->whereHas('profile', fn ($q) => $q->whereNotNull('username'))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('supplier_sector')
                ->whereColumn('supplier_sector.user_id', 'users.id'))
            ->where('is_active', true)
            ->with('profile:id,user_id,username,city')
            ->orderByDesc('created_at')
            ->get();

        foreach ($suppliers as $supplier) {
            $urls[] = [
                'loc' => $base.'/u/'.$supplier->profile->username,
                'priority' => '0.7',
                'changefreq' => 'weekly',
                'lastmod' => optional($supplier->updated_at)->toAtomString(),
            ];
        }

        // Sectors, their specialties, and the city pages under each.
        $sectors = Sector::query()->where('is_active', true)->orderBy('position')->get();
        $byParent = $sectors->groupBy('parent_id');

        foreach ($sectors->where('depth', 0) as $sector) {
            $urls[] = ['loc' => $base.'/suppliers/'.$sector->slug, 'priority' => '0.8', 'changefreq' => 'weekly'];

            foreach ($this->citySlugsFor($sector) as $citySlug) {
                $urls[] = [
                    'loc' => $base.'/suppliers/'.$sector->slug.'/city/'.$citySlug,
                    'priority' => '0.6',
                    'changefreq' => 'weekly',
                ];
            }

            foreach ($byParent->get($sector->id, collect()) as $specialty) {
                $urls[] = [
                    'loc' => $base.'/suppliers/'.$sector->slug.'/'.$specialty->slug,
                    'priority' => '0.7',
                    'changefreq' => 'weekly',
                ];

                foreach ($this->citySlugsFor($specialty) as $citySlug) {
                    $urls[] = [
                        'loc' => $base.'/suppliers/'.$sector->slug.'/'.$specialty->slug.'/city/'.$citySlug,
                        'priority' => '0.5',
                        'changefreq' => 'weekly',
                    ];
                }
            }
        }

        return $urls;
    }

    /**
     * City slugs that actually have suppliers in this sector node — we never
     * list a city page that would render empty.
     *
     * @return array<int, string>
     */
    private function citySlugsFor(Sector $node): array
    {
        $ids = $node->depth === 0
            ? $node->children()->pluck('id')->push($node->id)->all()
            : [$node->id];

        return DB::table('supplier_sector')
            ->join('users', 'users.id', '=', 'supplier_sector.user_id')
            ->join('profiles', 'profiles.user_id', '=', 'users.id')
            ->whereIn('supplier_sector.sector_id', $ids)
            ->where('users.is_active', true)
            ->whereNotNull('profiles.city')
            ->where('profiles.city', '<>', '')
            ->distinct()
            ->pluck('profiles.city')
            ->map(fn ($city) => Str::slug($city))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<int, array<string, string|null>> $urls */
    private function render(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>'.htmlspecialchars($url['loc'], ENT_XML1).'</loc>'."\n";
            if (! empty($url['lastmod'])) {
                $xml .= '    <lastmod>'.$url['lastmod'].'</lastmod>'."\n";
            }
            $xml .= '    <changefreq>'.$url['changefreq'].'</changefreq>'."\n";
            $xml .= '    <priority>'.$url['priority'].'</priority>'."\n";
            $xml .= "  </url>\n";
        }

        return $xml.'</urlset>'."\n";
    }

    /**
     * Tell Google the sitemap changed. Best-effort: a failed ping must never
     * surface to the user or break the request that triggered it.
     */
    public function ping(): bool
    {
        $sitemap = $this->siteUrl().'/sitemap.xml';

        try {
            $response = Http::timeout(5)->get('https://www.google.com/ping', ['sitemap' => $sitemap]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::info('Sitemap ping failed: '.$e->getMessage());

            return false;
        }
    }
}
