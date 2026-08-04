<?php

namespace App\Console\Commands;

use App\Services\Seo\SitemapGenerator;
use Illuminate\Console\Command;

/**
 * Regenerate the public sitemap from live data and (optionally) ping Google.
 * Run on a schedule and after a supplier joins the directory.
 */
class GenerateSitemapCommand extends Command
{
    protected $signature = 'sitemap:generate {--ping : Notify Google that the sitemap changed}';

    protected $description = 'Rebuild sitemap.xml from live directory data';

    public function handle(SitemapGenerator $generator): int
    {
        $count = $generator->generate();

        if ($count === null) {
            $this->warn('No sitemap path configured (sellchase.sitemap_path) — nothing written.');

            return self::SUCCESS;
        }

        $this->info("Sitemap written: {$count} URLs → ".$generator->outputPath());

        if ($this->option('ping')) {
            // IndexNow (Bing/Yandex/Seznam). Google dropped its ping endpoint in
            // 2023 and now discovers the sitemap from robots.txt.
            $this->info($generator->ping()
                ? 'IndexNow notified.'
                : 'IndexNow not notified (no key configured, or the request failed — ignored).');
        }

        return self::SUCCESS;
    }
}
