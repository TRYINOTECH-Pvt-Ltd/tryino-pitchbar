<?php

namespace App\Jobs\Crawl;

use App\Models\Source;
use App\Services\Crawl\SitemapDiscoverer;
use App\Support\Exceptions\UnsafeUrlException;
use App\Support\UrlSafetyGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CrawlSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 3 attempts with spaced backoff — the discovery fetch (sitemap.xml,
     * robots.txt, homepage) is one HTTP call to a site we don't control;
     * a transient DNS blip or 5xx used to fail the whole source on the
     * single attempt and force a manual Reindex click.
     */
    public int $tries = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(public string $sourceId) {}

    public function handle(SitemapDiscoverer $sitemap, UrlSafetyGuard $guard): void
    {
        $source = Source::query()->withoutWorkspaceScope()->find($this->sourceId);
        if ($source === null) {
            Log::info('jobs.target_missing', ['job' => static::class, 'id' => $this->sourceId]);

            return;
        }
        $source->forceFill(['status' => 'crawling'])->save();

        $url = $source->config['url'] ?? null;
        if (! is_string($url)) {
            $source->forceFill(['status' => 'failed', 'error' => 'No URL configured'])->save();

            return;
        }

        // SSRF gate. A workspace admin pasting
        // `http://169.254.169.254/...` or `http://localhost:6379/`
        // gets bounced here before any fan-out — without this, the
        // PlainHttpCrawler fallback would happily fetch from the
        // app's egress IP. We carry the rejection reason verbatim
        // to source.error so the operator sees exactly why.
        try {
            $guard->assertSafe($url);
        } catch (UnsafeUrlException $e) {
            $source->forceFill([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ])->save();

            return;
        }

        $maxPages = (int) config('services.crawl.max_pages_per_source', 50);

        // Only run sitemap discovery when the user explicitly chose "sitemap".
        // For type=url, crawl exactly the URL the user provided — never expand.
        // (Some sites, e.g. startech.com.bd, return the host-root sitemap.xml
        // for any path-prefixed sitemap.xml URL, which would silently fan out
        // into pages the user never asked for.)
        if ($source->type === 'sitemap') {
            $urls = $sitemap->discover($url, $maxPages);
            if ($urls === []) {
                $urls = [$url];
            }
        } else {
            $urls = [$url];
        }

        // Stagger dispatches with a small per-page delay so we don't burst
        // Cloudflare Browser Rendering and trip its concurrency limits.
        $i = 0;
        foreach ($urls as $u) {
            CrawlPageJob::dispatch($source->id, $u)
                ->onQueue('crawl')
                ->delay(now()->addSeconds($i * 2));
            $i++;
        }
    }

    public function failed(\Throwable $e): void
    {
        $source = Source::query()->withoutWorkspaceScope()->find($this->sourceId);
        if ($source === null || $source->status === 'indexed') {
            return;
        }
        $source->forceFill([
            'status' => 'failed',
            'error' => 'Crawl setup failed: '.Str::limit($e->getMessage(), 480),
        ])->save();
    }
}
