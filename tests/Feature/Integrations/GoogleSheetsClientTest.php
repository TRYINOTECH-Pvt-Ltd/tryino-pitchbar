<?php

use App\Http\Controllers\Admin\GoogleOAuthController;
use App\Services\Integrations\Google\GoogleException;
use App\Services\Integrations\Google\SheetsClient;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

function makeSheetsClient(array $responses): SheetsClient
{
    $handler = new MockHandler($responses);
    $stack = HandlerStack::create($handler);

    return new SheetsClient(new Guzzle(['handler' => $stack]));
}

test('metadata decodes spreadsheet title + sheets list', function () {
    $client = makeSheetsClient([
        new Response(200, [], json_encode([
            'properties' => ['title' => 'Q3 Pricing'],
            'sheets' => [
                ['properties' => ['sheetId' => 0, 'title' => 'Plans', 'gridProperties' => ['rowCount' => 50, 'columnCount' => 6]]],
                ['properties' => ['sheetId' => 1, 'title' => 'FAQ', 'gridProperties' => ['rowCount' => 20, 'columnCount' => 2]]],
            ],
        ])),
    ]);

    $meta = $client->metadata('1xyz', 'access-token');
    expect($meta['title'])->toBe('Q3 Pricing');
    expect($meta['sheets'])->toHaveCount(2);
    expect($meta['sheets'][0]['title'])->toBe('Plans');
    expect($meta['sheets'][0]['row_count'])->toBe(50);
});

test('pullRows turns the first row into headers + builds associative arrays', function () {
    $client = makeSheetsClient([
        new Response(200, [], json_encode([
            'range' => 'Plans!A:C',
            'majorDimension' => 'ROWS',
            'values' => [
                ['Plan name', 'Price', 'Conversations'],
                ['Starter', '$49', '500'],
                ['Pro', '$149', '5000'],
            ],
        ])),
    ]);

    $rows = $client->pullRows('1xyz', 'Plans', 'access-token');
    expect($rows)->toHaveCount(2);
    expect($rows[0])->toBe([
        'Plan name' => 'Starter',
        'Price' => '$49',
        'Conversations' => '500',
    ]);
    expect($rows[1]['Plan name'])->toBe('Pro');
});

test('pullRows synthesises col_N keys for missing headers', function () {
    $client = makeSheetsClient([
        new Response(200, [], json_encode([
            'values' => [
                ['Name', '', 'Notes'],
                ['Alpha', 'a-value', 'a note'],
            ],
        ])),
    ]);

    $rows = $client->pullRows('1xyz', 'Tab', 'token');
    expect(array_keys($rows[0]))->toBe(['Name', 'col_2', 'Notes']);
});

test('pullRows skips fully empty rows', function () {
    $client = makeSheetsClient([
        new Response(200, [], json_encode([
            'values' => [
                ['Plan', 'Price'],
                ['Starter', '49'],
                ['', ''],
                ['Pro', '149'],
            ],
        ])),
    ]);

    $rows = $client->pullRows('1xyz', 'Tab', 'token');
    expect($rows)->toHaveCount(2);
});

test('metadata raises a GoogleException on 4xx responses', function () {
    $client = makeSheetsClient([
        new Response(403, [], json_encode([
            'error' => ['code' => 403, 'message' => 'The user does not have sufficient permissions for this file.'],
        ])),
    ]);

    expect(fn () => $client->metadata('1xyz', 'access-token'))
        ->toThrow(GoogleException::class);
});

test('pullRows raises a GoogleException on 4xx responses', function () {
    $client = makeSheetsClient([
        new Response(404, [], json_encode([
            'error' => ['code' => 404, 'message' => 'Requested entity was not found.'],
        ])),
    ]);

    expect(fn () => $client->pullRows('1xyz', 'NoSuchTab', 'access-token'))
        ->toThrow(GoogleException::class);
});

test('OAuth scope list includes spreadsheets.readonly', function () {
    expect(GoogleOAuthController::SCOPES)
        ->toContain('https://www.googleapis.com/auth/spreadsheets.readonly');
});
