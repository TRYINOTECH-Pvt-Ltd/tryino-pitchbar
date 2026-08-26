<?php

namespace App\Services\Integrations\Google;

use GuzzleHttp\Client as Guzzle;

/**
 * Read-only wrapper over the Google Sheets API v4. Just enough to:
 *   - list the sheets (tabs) inside a spreadsheet
 *   - pull a sheet's values as rows
 *   - report the sheet's title and last-modified timestamp
 *
 * Auth: the caller passes an access_token already refreshed by
 * GoogleClient + GoogleTokenStore. We don't refresh inside this class
 * because the existing Drive/Docs flow has the refresh machinery; we
 * reuse the same access token.
 *
 * Errors raise GoogleException.
 */
class SheetsClient
{
    public function __construct(
        private readonly Guzzle $http,
    ) {}

    public static function default(?Guzzle $http = null): self
    {
        return new self($http ?? new Guzzle(['timeout' => 20]));
    }

    /**
     * @return array{title: string, sheets: array<int, array{title: string, sheet_id: int, row_count: int, column_count: int}>, modified_time: ?string}
     */
    public function metadata(string $spreadsheetId, string $accessToken): array
    {
        $response = $this->http->get(
            "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}",
            [
                'headers' => ['Authorization' => "Bearer {$accessToken}"],
                'query' => ['fields' => 'properties.title,sheets(properties(sheetId,title,gridProperties))'],
                'http_errors' => false,
            ],
        );

        if ($response->getStatusCode() >= 400) {
            $body = (string) $response->getBody();
            throw new GoogleException("Sheets API metadata failed ({$response->getStatusCode()}): {$body}");
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (! is_array($payload)) {
            throw new GoogleException('Sheets API returned a non-JSON body for metadata.');
        }

        $sheets = [];
        foreach (($payload['sheets'] ?? []) as $sheet) {
            $props = $sheet['properties'] ?? [];
            $grid = $props['gridProperties'] ?? [];
            $sheets[] = [
                'title' => (string) ($props['title'] ?? ''),
                'sheet_id' => (int) ($props['sheetId'] ?? 0),
                'row_count' => (int) ($grid['rowCount'] ?? 0),
                'column_count' => (int) ($grid['columnCount'] ?? 0),
            ];
        }

        return [
            'title' => (string) ($payload['properties']['title'] ?? ''),
            'sheets' => $sheets,
            'modified_time' => null,
        ];
    }

    /**
     * Pull a sheet's values. First row is treated as the header → each
     * subsequent row is returned as a column-name → value associative
     * array. Sheets without an explicit header still work — keys fall
     * back to "col_1", "col_2", … so the chunk metadata stays usable.
     *
     * @return array<int, array<string, string>>
     */
    public function pullRows(string $spreadsheetId, string $sheetTitle, string $accessToken): array
    {
        $range = rawurlencode($sheetTitle);
        $response = $this->http->get(
            "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}",
            [
                'headers' => ['Authorization' => "Bearer {$accessToken}"],
                'query' => ['majorDimension' => 'ROWS'],
                'http_errors' => false,
            ],
        );

        if ($response->getStatusCode() >= 400) {
            $body = (string) $response->getBody();
            throw new GoogleException("Sheets API values failed ({$response->getStatusCode()}): {$body}");
        }

        $payload = json_decode((string) $response->getBody(), true);
        $values = (array) ($payload['values'] ?? []);

        if ($values === []) {
            return [];
        }

        $headerRow = array_map('strval', (array) array_shift($values));
        $headers = [];
        foreach ($headerRow as $i => $h) {
            $clean = trim((string) $h);
            $headers[] = $clean !== '' ? $clean : ('col_'.($i + 1));
        }

        $rows = [];
        foreach ($values as $row) {
            $row = (array) $row;
            $entry = [];
            foreach ($headers as $i => $header) {
                $entry[$header] = isset($row[$i]) ? (string) $row[$i] : '';
            }
            // Skip rows that are entirely empty so embedding doesn't
            // waste budget on dead lines.
            if (array_filter($entry, fn ($v) => $v !== '') !== []) {
                $rows[] = $entry;
            }
        }

        return $rows;
    }
}
