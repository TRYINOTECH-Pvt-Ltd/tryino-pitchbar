<?php

namespace App\Services\Integrations\Notion;

use GuzzleHttp\Client as Guzzle;

/**
 * Thin Notion REST client. Doesn't try to be exhaustive — just enough for:
 *   - OAuth code exchange
 *   - searching pages the integration has access to
 *   - fetching a page's blocks (recursively for child blocks)
 *
 * All calls return decoded arrays. HTTP errors raise NotionException.
 */
class NotionClient
{
    public const NOTION_VERSION = '2022-06-28';

    public function __construct(
        private readonly Guzzle $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    public static function default(string $clientId, string $clientSecret, ?Guzzle $http = null): self
    {
        return new self(
            $http ?? new Guzzle(['timeout' => 15]),
            $clientId,
            $clientSecret,
        );
    }

    /**
     * Exchange an OAuth `code` for an access token + workspace metadata.
     *
     * @return array{access_token: string, workspace_id: string, workspace_name: ?string, bot_id: string}
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = $this->http->post('https://api.notion.com/v1/oauth/token', [
            'json' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ],
            'auth' => [$this->clientId, $this->clientSecret],
            'headers' => [
                'Content-Type' => 'application/json',
                'Notion-Version' => self::NOTION_VERSION,
            ],
            'http_errors' => false,
        ]);

        $body = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 400 || ! is_array($body)) {
            throw new NotionException('OAuth exchange failed: '.($body['error_description'] ?? $body['error'] ?? 'unknown'));
        }

        return [
            'access_token' => (string) $body['access_token'],
            'workspace_id' => (string) ($body['workspace_id'] ?? ''),
            'workspace_name' => $body['workspace_name'] ?? null,
            'bot_id' => (string) ($body['bot_id'] ?? ''),
        ];
    }

    /**
     * Returns the search results from /v1/search, capped at $limit.
     *
     * @return array<int, array{id: string, object: string, url: ?string, properties: array}>
     */
    public function search(string $token, string $query = '', int $limit = 50): array
    {
        $cursor = null;
        $out = [];

        do {
            $payload = ['filter' => ['property' => 'object', 'value' => 'page']];
            if ($query !== '') {
                $payload['query'] = $query;
            }
            if ($cursor !== null) {
                $payload['start_cursor'] = $cursor;
            }

            $response = $this->http->post('https://api.notion.com/v1/search', [
                'json' => $payload,
                'headers' => $this->authHeaders($token),
                'http_errors' => false,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            if ($response->getStatusCode() >= 400 || ! is_array($body)) {
                throw new NotionException('Search failed: '.($body['message'] ?? 'unknown'));
            }

            foreach ($body['results'] ?? [] as $r) {
                $out[] = [
                    'id' => (string) $r['id'],
                    'object' => (string) ($r['object'] ?? ''),
                    'url' => $r['url'] ?? null,
                    'properties' => (array) ($r['properties'] ?? []),
                ];
                if (count($out) >= $limit) {
                    break 2;
                }
            }

            $cursor = $body['has_more'] ? ($body['next_cursor'] ?? null) : null;
        } while ($cursor !== null);

        return $out;
    }

    /**
     * @return array{title: string, url: ?string, last_edited_time: ?string}
     */
    public function getPage(string $token, string $pageId): array
    {
        $response = $this->http->get("https://api.notion.com/v1/pages/{$pageId}", [
            'headers' => $this->authHeaders($token),
            'http_errors' => false,
        ]);
        $body = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 400 || ! is_array($body)) {
            throw new NotionException('getPage failed: '.($body['message'] ?? 'unknown'));
        }

        return [
            'title' => $this->extractPageTitle($body),
            'url' => $body['url'] ?? null,
            'last_edited_time' => $body['last_edited_time'] ?? null,
        ];
    }

    /**
     * Returns the flattened block list for a page (recursing one level into
     * child_page / toggle / column / etc. children).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBlocks(string $token, string $blockId, int $depth = 0, int $maxDepth = 3): array
    {
        if ($depth > $maxDepth) {
            return [];
        }

        $cursor = null;
        $out = [];

        do {
            $url = "https://api.notion.com/v1/blocks/{$blockId}/children?page_size=100";
            if ($cursor !== null) {
                $url .= '&start_cursor='.urlencode($cursor);
            }

            $response = $this->http->get($url, [
                'headers' => $this->authHeaders($token),
                'http_errors' => false,
            ]);
            $body = json_decode((string) $response->getBody(), true);
            if ($response->getStatusCode() >= 400 || ! is_array($body)) {
                throw new NotionException('getBlocks failed: '.($body['message'] ?? 'unknown'));
            }

            foreach ($body['results'] ?? [] as $block) {
                $out[] = $block;
                if ($block['has_children'] ?? false) {
                    foreach ($this->getBlocks($token, (string) $block['id'], $depth + 1, $maxDepth) as $child) {
                        $out[] = $child;
                    }
                }
            }

            $cursor = $body['has_more'] ? ($body['next_cursor'] ?? null) : null;
        } while ($cursor !== null);

        return $out;
    }

    private function extractPageTitle(array $page): string
    {
        // Pages with title properties.
        foreach (($page['properties'] ?? []) as $name => $prop) {
            if (($prop['type'] ?? null) === 'title') {
                $rich = $prop['title'] ?? [];

                return collect($rich)->pluck('plain_text')->implode('');
            }
        }

        // Database rows / fallback.
        return 'Untitled';
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $token): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'Notion-Version' => self::NOTION_VERSION,
            'Content-Type' => 'application/json',
        ];
    }
}
