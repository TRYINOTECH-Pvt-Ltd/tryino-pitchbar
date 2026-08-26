<?php

namespace App\Support;

use App\Support\Exceptions\UnsafeUrlException;

/**
 * Reject URLs whose host resolves to a private/internal IP. Used by
 * the crawler entry points (CrawlSourceJob, PlainHttpCrawler) and the
 * widget's AutoIndexPageVisit guard. Without this, a workspace admin
 * could submit `http://169.254.169.254/...` or `http://localhost:6379/`
 * and watch the crawler exfiltrate cloud-metadata credentials or
 * internal-network responses into their own KB.
 *
 * Two layers, each toggleable so hot-path callers can skip DNS:
 *
 *   1. Hostname pattern blocklist (always on). Cheap, deterministic.
 *      Catches `localhost`, `127.x`, `10.x`, `192.168.x`, `172.16-31.x`,
 *      `169.254.x` (cloud metadata), `0.x`, `::1`, `fe80::`, `fc00::/7`,
 *      `*.local`, `*.internal`.
 *   2. DNS resolution (opt-in via `resolveHostnames=true`). Catches the
 *      "evil-rebind.example.com points at 127.0.0.1" variant where an
 *      attacker controls DNS for a domain they own. Skipped by default
 *      because dns_get_record adds 5-50ms per call and we don't want
 *      that on the /widget/init hot path.
 *
 * Crawl jobs (CrawlSourceJob, PlainHttpCrawler) flip resolveHostnames
 * on — they're already paying network latency to fetch, the rebind
 * protection is cheap relative to the fetch itself, and missing one
 * exposes raw Guzzle on this server's egress IP.
 *
 * Resolved-IP checks use PHP's filter_var() FILTER_FLAG_NO_PRIV_RANGE
 * + FILTER_FLAG_NO_RES_RANGE — the same rules Symfony's HttpClient
 * applies internally.
 */
class UrlSafetyGuard
{
    /**
     * Hostname patterns blocked before any DNS resolution. Lets us
     * fail fast on the obvious `127.x` / `localhost` / `*.internal`
     * forms without paying the DNS round-trip.
     */
    private const HOSTNAME_PATTERNS = [
        '#^localhost$#i',
        '#^127\.#',
        '#^10\.#',
        '#^192\.168\.#',
        '#^172\.(1[6-9]|2[0-9]|3[01])\.#',
        '#^169\.254\.#',     // link-local + cloud metadata
        '#^0\.#',            // 0.0.0.0/8
        '#^::1$#',           // IPv6 loopback
        '#^fe80:#i',         // IPv6 link-local
        '#^fc00:#i',         // IPv6 ULA
        '#^fd[0-9a-f]{2}:#i',
        '#\.local$#i',
        '#\.internal$#i',
    ];

    /**
     * Throw if the URL is unsafe (private host, unresolvable when
     * `resolveHostnames=true`, or non-http(s) scheme). Otherwise
     * return cleanly.
     *
     * @throws UnsafeUrlException
     */
    public function assertSafe(string $url, bool $resolveHostnames = true): void
    {
        $reason = $this->unsafeReason($url, $resolveHostnames);
        if ($reason !== null) {
            throw new UnsafeUrlException($reason);
        }
    }

    /**
     * Boolean wrapper for callers that don't want to catch. Defaults
     * to pattern-only (no DNS) for hot-path use; pass true to fold in
     * DNS rebind protection.
     */
    public function isSafe(string $url, bool $resolveHostnames = false): bool
    {
        return $this->unsafeReason($url, $resolveHostnames) === null;
    }

    /**
     * @return string|null Reason the URL is unsafe, or null when safe.
     */
    private function unsafeReason(string $url, bool $resolveHostnames): ?string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return 'Unparseable URL.';
        }

        // Reject obviously dangerous schemes FIRST — even when
        // parse_url couldn't find a host, `file:///etc/passwd`,
        // `gopher://...`, `javascript:`, and `data:` must all bounce
        // with a clear "disallowed scheme" rather than the generic
        // "unparseable URL" reason. Without this, callers can't tell
        // a typo apart from an attack.
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme === '') {
            return 'Unparseable URL.';
        }
        if ($scheme !== 'http' && $scheme !== 'https') {
            return "Disallowed scheme: {$scheme}.";
        }

        if (! isset($parts['host'])) {
            return 'Unparseable URL.';
        }

        $host = trim((string) $parts['host'], '[]');
        if ($host === '') {
            return 'Empty host.';
        }

        foreach (self::HOSTNAME_PATTERNS as $pattern) {
            if (preg_match($pattern, $host) === 1) {
                return 'Refusing to crawl an internal / loopback / link-local host.';
            }
        }

        // Numeric IP literal — pattern check is decisive. Fall through
        // and let filter_var assess any IP form the patterns didn't
        // recognise (CIDR edge cases, IPv6 numerics).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! $this->isPublicIp($host)) {
                return 'Refusing to crawl a private IP address.';
            }

            return null;
        }

        if (! $resolveHostnames) {
            return null;
        }

        // Resolve the hostname. If ANY A/AAAA record is private,
        // refuse — protects against `evil-rebind.example.com →
        // 127.0.0.1` where the attacker controls DNS.
        $ips = $this->resolve($host);
        if ($ips === []) {
            return 'Hostname did not resolve to any IP address.';
        }
        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return 'Hostname resolves to a private IP address.';
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            $v4 = @gethostbynamel($host);

            return is_array($v4) ? array_values($v4) : [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
