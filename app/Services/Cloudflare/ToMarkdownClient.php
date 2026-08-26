<?php

namespace App\Services\Cloudflare;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

/**
 * Cloudflare Workers AI `toMarkdown` — converts PDF / DOCX / XLSX /
 * CSV / HTML / XML / ODT documents to structured Markdown in one HTTP
 * round-trip. Lets us drop Smalot/PhpWord/League CSV out of the hot
 * code path for non-trivial files where in-process PHP parsers tend
 * to fall over on real-world documents.
 *
 * REST surface:
 *   POST https://api.cloudflare.com/client/v4/accounts/{ACCOUNT_ID}/ai/tomarkdown
 *   Authorization: Bearer {API_TOKEN}
 *   Content-Type: multipart/form-data; files=@document.pdf
 *
 * Pricing: free (0 Neurons) for every format we use here. Only image
 * → markdown ingestion would consume Neurons because Cloudflare runs
 * an object-detection + captioning model behind it. We don't send
 * images.
 */
class ToMarkdownClient
{
    public function __construct(
        private readonly Guzzle $http,
        private readonly string $accountId,
        private readonly string $apiToken,
    ) {}

    public static function default(string $accountId, string $apiToken, ?Guzzle $http = null): self
    {
        // 60s overall, 10s connect. Large PDFs (200+ pages, scanned)
        // can take 30-45s on a cold worker; the default 30s ceiling
        // we use on Vectorize is too tight for this endpoint.
        return new self(
            $http ?? new Guzzle(['timeout' => 60, 'connect_timeout' => 10]),
            $accountId,
            $apiToken,
        );
    }

    /**
     * Convert a single document to Markdown.
     *
     * @param  string  $bytes  Raw file bytes.
     * @param  string  $filename  Original filename (used by Cloudflare to pick the right decoder).
     * @param  string|null  $mimeType  Optional MIME hint; Cloudflare also sniffs the extension.
     * @return string Markdown body (may be the empty string if Cloudflare returned no text).
     *
     * @throws RuntimeException On HTTP failure, non-2xx, or per-file conversion error.
     */
    public function convert(string $bytes, string $filename, ?string $mimeType = null): string
    {
        $url = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/ai/tomarkdown";

        $part = [
            'name' => 'files',
            'contents' => $bytes,
            'filename' => $filename,
        ];
        if ($mimeType !== null && $mimeType !== '') {
            $part['headers'] = ['Content-Type' => $mimeType];
        }

        try {
            $response = $this->http->request('POST', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                    'Accept' => 'application/json',
                ],
                'multipart' => [$part],
            ]);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
            throw new RuntimeException("toMarkdown POST failed for {$filename}: {$body}", previous: $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("toMarkdown returned non-JSON body for {$filename}");
        }

        // Cloudflare wraps every API response in {success, errors, result}.
        // A 200 with success=false still signals a logical failure (e.g.,
        // unsupported format) — surface it.
        if (isset($decoded['success']) && $decoded['success'] === false) {
            $msg = $decoded['errors'][0]['message'] ?? 'unknown error';
            throw new RuntimeException("toMarkdown rejected {$filename}: {$msg}");
        }

        $results = $decoded['result'] ?? [];
        if (! is_array($results) || $results === []) {
            throw new RuntimeException("toMarkdown returned no result for {$filename}");
        }

        // Single-file POSTs still return an array. Take the first entry
        // and surface its format/error fields explicitly so callers see
        // exactly why a conversion was rejected.
        $first = $results[0];
        $format = (string) ($first['format'] ?? '');
        if ($format !== 'markdown') {
            $err = (string) ($first['error'] ?? 'unknown conversion error');
            throw new RuntimeException("toMarkdown could not convert {$filename}: {$err}");
        }

        return (string) ($first['data'] ?? '');
    }
}
