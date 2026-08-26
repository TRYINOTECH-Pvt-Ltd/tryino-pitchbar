<?php

namespace App\Services\Integrations\Google;

use GuzzleHttp\Client as Guzzle;

/**
 * Minimal Google OAuth + Drive client. Just enough for:
 *   - exchanging an authorization code for an access token (and refresh
 *     token, since Drive scopes are long-lived)
 *   - exporting a Google Doc as plain text via Drive API v3
 *   - refreshing an access token when it expires
 *
 * Scopes used:
 *   https://www.googleapis.com/auth/drive.readonly
 *   https://www.googleapis.com/auth/documents.readonly
 *
 * Errors raise GoogleException.
 */
class GoogleClient
{
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
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, scope: ?string, token_type: ?string}
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = $this->http->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ],
            'http_errors' => false,
        ]);

        $body = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 400 || ! is_array($body) || ! isset($body['access_token'])) {
            throw new GoogleException('OAuth exchange failed: '.($body['error_description'] ?? $body['error'] ?? 'unknown'));
        }

        return [
            'access_token' => (string) $body['access_token'],
            'refresh_token' => $body['refresh_token'] ?? null,
            'expires_in' => (int) ($body['expires_in'] ?? 3600),
            'scope' => $body['scope'] ?? null,
            'token_type' => $body['token_type'] ?? null,
        ];
    }

    /**
     * @return array{access_token: string, expires_in: int}
     */
    public function refresh(string $refreshToken): array
    {
        $response = $this->http->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ],
            'http_errors' => false,
        ]);

        $body = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 400 || ! is_array($body) || ! isset($body['access_token'])) {
            throw new GoogleException('Refresh failed: '.($body['error_description'] ?? $body['error'] ?? 'unknown'));
        }

        return [
            'access_token' => (string) $body['access_token'],
            'expires_in' => (int) ($body['expires_in'] ?? 3600),
        ];
    }

    /**
     * Returns the document's title + plain-text content.
     * Only works for Google Docs (mimeType: application/vnd.google-apps.document).
     *
     * @return array{title: string, text: string, modified_time: ?string}
     */
    public function getDoc(string $accessToken, string $fileId): array
    {
        // 1. Metadata
        $meta = $this->http->get("https://www.googleapis.com/drive/v3/files/{$fileId}", [
            'query' => ['fields' => 'id,name,mimeType,modifiedTime'],
            'headers' => ['Authorization' => "Bearer {$accessToken}"],
            'http_errors' => false,
        ]);
        $metaBody = json_decode((string) $meta->getBody(), true);
        if ($meta->getStatusCode() >= 400 || ! is_array($metaBody)) {
            throw new GoogleException('getDoc metadata failed: '.($metaBody['error']['message'] ?? 'unknown'));
        }

        $mime = (string) ($metaBody['mimeType'] ?? '');
        if ($mime !== 'application/vnd.google-apps.document') {
            throw new GoogleException("File is not a Google Doc (mimeType={$mime}).");
        }

        // 2. Export as text/plain
        $export = $this->http->get("https://www.googleapis.com/drive/v3/files/{$fileId}/export", [
            'query' => ['mimeType' => 'text/plain'],
            'headers' => ['Authorization' => "Bearer {$accessToken}"],
            'http_errors' => false,
        ]);
        if ($export->getStatusCode() >= 400) {
            throw new GoogleException('export failed: HTTP '.$export->getStatusCode());
        }

        return [
            'title' => (string) ($metaBody['name'] ?? 'Untitled'),
            'text' => (string) $export->getBody(),
            'modified_time' => $metaBody['modifiedTime'] ?? null,
        ];
    }
}
