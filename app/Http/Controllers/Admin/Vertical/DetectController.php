<?php

namespace App\Http\Controllers\Admin\Vertical;

use App\Models\Agent;
use App\Services\Vertical\SiteTypeDetector;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Detects the vertical of a website by fetching its homepage and scoring
 * the structured signals (og:type, JSON-LD, generator, URL shape).
 *
 * Always returns 200 OK so the wizard can proceed even when the fetch
 * fails — JS-heavy SPAs and auth-walled hosts return empty SSR HTML and
 * fall through to `generic`. The admin can override in the UI.
 *
 * Cached on the agent row in `vertical_signals` for the admin UI.
 */
class DetectController
{
    public function __construct(
        private SiteTypeDetector $detector,
        private Client $http,
    ) {}

    public function __invoke(Request $request, Agent $agent): JsonResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        $data = $request->validate([
            'url' => ['required', 'url', 'max:2000'],
        ]);

        $html = $this->fetch($data['url']);

        if ($html === null) {
            $result = [
                'type' => 'generic',
                'confidence' => 0.0,
                'alternatives' => [],
                'signals' => ['fetch_failed'],
            ];
        } else {
            $result = $this->detector->detect($html, $data['url']);
        }

        $agent->forceFill([
            'vertical_signals' => array_merge($result, [
                'detected_at' => now()->toIso8601String(),
                'detected_url' => $data['url'],
            ]),
        ])->save();

        return response()->json(['data' => $result]);
    }

    /**
     * Returns the fetched HTML body, or null when the fetch fails.
     * 5-second timeout, follows redirects, never throws — caller falls
     * back to the `generic` preset on null.
     *
     * Uses a real browser User-Agent. A bot-flavoured UA (PitchbarBot)
     * gets blocked by Shopify, Cloudflare-fronted sites, and most major
     * SaaS landing pages — making detection useless on the very sites
     * the wizard most needs to read. We're fetching exactly one
     * structured-metadata page (not crawling), so this stays well
     * within polite-robot territory.
     */
    private function fetch(string $url): ?string
    {
        try {
            $response = $this->http->get($url, [
                'timeout' => 5,
                'connect_timeout' => 3,
                'http_errors' => false,
                'allow_redirects' => true,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ],
            ]);
        } catch (GuzzleException) {
            return null;
        } catch (\Throwable) {
            return null;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return null;
        }

        return (string) $response->getBody();
    }
}
