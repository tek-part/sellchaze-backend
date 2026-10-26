<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $urls = [];

        $urls[] = [
            'loc' => url('/'),
            'lastmod' => now()->toW3cString(),
            'changefreq' => 'weekly',
            'priority' => '1.0',
        ];

        if (\Route::has('blog.index')) {
            $urls[] = [
                'loc' => url('/blog'),
                'lastmod' => Article::published()->latest('updated_at')->first()?->updated_at?->toW3cString() ?? now()->toW3cString(),
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ];

            foreach (Article::published()->get() as $article) {
                $urls[] = [
                    'loc' => url('/blog/' . $article->slug),
                    'lastmod' => $article->updated_at->toW3cString(),
                    'changefreq' => 'monthly',
                    'priority' => '0.6',
                ];
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $u) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . htmlspecialchars($u['loc']) . '</loc>' . "\n";
            $xml .= '    <lastmod>' . ($u['lastmod'] ?? now()->toW3cString()) . '</lastmod>' . "\n";
            $xml .= '    <changefreq>' . ($u['changefreq'] ?? 'weekly') . '</changefreq>' . "\n";
            $xml .= '    <priority>' . ($u['priority'] ?? '0.5') . '</priority>' . "\n";
            $xml .= '  </url>' . "\n";
        }

        $xml .= '</urlset>';

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Charset' => 'UTF-8',
        ]);
    }
}
