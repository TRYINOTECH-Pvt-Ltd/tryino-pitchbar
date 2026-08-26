<?php

namespace App\Services\Crawl;

use App\Jobs\Crawl\CrawlPageJob;
use App\Models\Agent;
use App\Models\Document;
use App\Models\Source;
use App\Support\CanonicalUrl;
use App\Support\UrlSafetyGuard;
use Illuminate\Support\Facades\Cache;

/**
 * "Auto-index on visit" — when a visitor lands on a page the agent
 * hasn't seen before, silently dispatch a crawl for that URL so the
 * agent's knowledge base grows passively as real visitors browse.
 *
 * This service is the gatekeeper that decides whether to dispatch.
 * It runs on the request hot path (during /init), so every guard
 * here must be cheap.
 *
 * Guards (all must pass):
 *   1. Feature flag on agent (auto_index_visited_pages)
 *   2. URL is well-formed and uses http/https
 *   3. Origin matches agent.allowed_origins (when explicit list set)
 *   4. Path doesn't look like authenticated/private (admin/checkout/etc.)
 *   5. URL not already indexed for this agent (Document dedup)
 *   6. Per-agent rate limit (max 30 new auto-crawls per hour)
 */
class AutoIndexPageVisit
{
    public const MAX_PER_HOUR = 30;

    public function __construct(private readonly UrlSafetyGuard $guard = new UrlSafetyGuard) {}

    /**
     * Path patterns we never auto-index — high false-positive rate for
     * private/transactional pages where indexing the rendered HTML
     * either fails (login wall) or leaks PII.
     */
    private const SKIP_PATH_PATTERNS = [
        '#/account(/|$)#i',
        '#/admin(/|$)#i',
        '#/checkout(/|$)#i',
        '#/cart(/|$)#i',
        '#/login(/|$)#i',
        '#/signin(/|$)#i',
        '#/signup(/|$)#i',
        '#/register(/|$)#i',
        '#/logout(/|$)#i',
        '#/my-?account(/|$)#i',
        '#/orders?(/|$)#i',
        '#/profile(/|$)#i',
        '#/settings(/|$)#i',
        '#/password(/|$)#i',
        '#/auth(/|$)#i',
    ];

    /**
     * Try to dispatch an auto-index for this URL. Returns true if the
     * crawl was dispatched, false otherwise (and the reason is logged
     * via debug-level log so it stays out of production noise).
     *
     * @param  string|null  $requestOrigin  The browser-set Origin header
     *                                      (already validated against agent.allowed_origins by the calling
     *                                      controller). When the agent uses '*' as allowed_origins, this
     *                                      becomes the effective constraint — auto-index will only fire
     *                                      if the page_url's origin matches the verified request origin.
     */
    public function attempt(Agent $agent, string $url, ?string $requestOrigin = null): bool
    {
        if (! $agent->auto_index_visited_pages) {
            return false;
        }

        $normalized = $this->normalizeUrl($url);
        if ($normalized === null) {
            return false;
        }

        if ($this->hostIsPrivate($normalized)) {
            return false;
        }

        if (! $this->originAllowed($normalized, $agent, $requestOrigin)) {
            return false;
        }

        if ($this->pathLooksPrivate($normalized)) {
            return false;
        }

        if ($this->alreadyIndexed($agent->id, $normalized)) {
            return false;
        }

        if (! $this->withinRateLimit($agent->id)) {
            return false;
        }

        $source = $this->ensureAutoSource($agent);

        CrawlPageJob::dispatch($source->id, $normalized)->onQueue('crawl');

        return true;
    }

    /**
     * Delegates to the shared CanonicalUrl helper so dedup, citation
     * matching, and Knowledge identity all use the same canonical form.
     */
    private function normalizeUrl(string $url): ?string
    {
        return CanonicalUrl::for($url);
    }

    /**
     * Block private / internal / loopback / link-local / cloud-metadata
     * hosts. Delegates to the shared UrlSafetyGuard so the auto-index
     * path, the explicit Add-Source path, and the PlainHttpCrawler
     * fallback all agree on what "unsafe" means. Cloudflare Browser
     * Rendering resolves and fetches from its own egress, so SSRF on
     * our server isn't the only concern — we also don't want to
     * ingest intranet pages into a workspace's knowledge base.
     */
    private function hostIsPrivate(string $url): bool
    {
        return ! $this->guard->isSafe($url);
    }

    private function originAllowed(string $url, Agent $agent, ?string $requestOrigin = null): bool
    {
        $pageOrigin = $this->originOf($url);
        // Normalise stored values so trailing slashes / case differences
        // don't bite the customer here either. Empty / malformed entries
        // get filtered.
        $allowed = array_values(array_filter(array_map(
            fn ($o) => $this->normalizeOrigin((string) $o),
            (array) ($agent->allowed_origins ?? []),
        ), static fn (string $o) => $o !== ''));

        // Wildcard / no list: the InitController's CORS check already
        // approved the request's Origin header against the same list.
        // So as long as the page_url is on the SAME origin the visitor's
        // browser actually came from, indexing it is fine — we're not
        // crawling arbitrary third-party domains, just the domain that
        // legitimately hosts the widget. Without a verified request
        // origin we still refuse (defense-in-depth).
        if ($allowed === [] || in_array('*', $allowed, true)) {
            if ($requestOrigin === null || $requestOrigin === '') {
                return false;
            }

            return $this->normalizeOrigin($requestOrigin) === $pageOrigin;
        }

        return in_array($pageOrigin, $allowed, true);
    }

    /**
     * Strip whitespace + lowercase scheme+host so a request Origin
     * matches the format originOf() emits for a page URL.
     */
    private function normalizeOrigin(string $origin): string
    {
        $parts = parse_url(trim($origin));
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return strtolower(trim($origin));
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    private function originOf(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    private function pathLooksPrivate(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        foreach (self::SKIP_PATH_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function alreadyIndexed(string $agentId, string $url): bool
    {
        // withoutWorkspaceScope: this runs in the widget's /init request,
        // which has no authenticated user and no CurrentWorkspace bound.
        // Tenancy is still enforced because the caller verified the
        // agent's allowed_origins matched, and we filter by agent_id.
        return Document::query()
            ->withoutWorkspaceScope()
            ->where('agent_id', $agentId)
            ->where('url', $url)
            ->exists();
    }

    /**
     * Token bucket — at most MAX_PER_HOUR auto-crawls per agent. This
     * prevents a viral page from blowing through Cloudflare Browser
     * Rendering's per-account concurrency on a flash-crowd day.
     *
     * Uses Cache::add + Cache::increment (atomic) so concurrent visits
     * to the same agent in the same hour can't all-pass the check at
     * count=29. The check-then-decrement-on-fail pattern means we
     * occasionally reject one extra request but never overspend.
     */
    private function withinRateLimit(string $agentId): bool
    {
        $key = "auto-index:agent:{$agentId}:hour:".now()->format('YmdH');
        // Cache::add is a no-op if the key exists, so the TTL is set
        // exactly once when the bucket is first created.
        Cache::add($key, 0, now()->addHour());
        $count = (int) Cache::increment($key);
        if ($count > self::MAX_PER_HOUR) {
            // We over-incremented — pull it back so a later request in
            // the same hour still gets a chance if rejections bunched.
            Cache::decrement($key);

            return false;
        }

        return true;
    }

    /**
     * Lazy-create a single Source per agent for "auto" content so the
     * dashboard can group these documents under one row labeled
     * "Auto-indexed from visitors".
     *
     * If the existing auto Source landed in 'failed' from a prior
     * crawl that produced no documents, flip it back to 'crawling'
     * before dispatching — otherwise the dashboard would keep showing
     * "failed" indefinitely even as new pages successfully index.
     */
    private function ensureAutoSource(Agent $agent): Source
    {
        // withoutWorkspaceScope: widget /init context has no CurrentWorkspace
        // bound. Tenancy is preserved by the explicit agent_id filter — the
        // caller already verified this agent is the right one for the request.
        $source = Source::query()
            ->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->where('type', 'auto')
            ->first();

        if ($source !== null) {
            if (in_array($source->status, ['failed', 'pending'], true)) {
                $source->forceFill([
                    'status' => 'crawling',
                    'error' => null,
                ])->save();
            }

            return $source;
        }

        return Source::create([
            'agent_id' => $agent->id,
            'type' => 'auto',
            'status' => 'crawling',
            'config' => ['label' => 'Auto-indexed from visitors'],
        ]);
    }
}
