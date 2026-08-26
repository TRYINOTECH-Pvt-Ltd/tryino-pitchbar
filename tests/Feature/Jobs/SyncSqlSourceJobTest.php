<?php

use App\Jobs\Crawl\IndexDocumentJob;
use App\Jobs\Crawl\SyncSqlSourceJob;
use App\Models\Agent;
use App\Models\Document;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\Crawl\SqlConnector;
use Illuminate\Support\Facades\Queue;

function makeSqlSource(array $config = [], array $credentials = []): Source
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return Source::create([
        'agent_id' => $agent->id,
        'type' => 'sql',
        'status' => 'pending',
        'config' => array_merge([
            'driver' => 'mysql',
            'query' => 'SELECT id, title, body FROM articles',
            'title' => 'Test SQL',
            'title_column' => 'title',
            'body_column' => 'body',
        ], $config),
        'credentials_encrypted' => array_merge([
            'driver' => 'mysql',
            'host' => 'db.example.com',
            'port' => 3306,
            'database' => 'd',
            'username' => 'u',
            'password' => 'p',
        ], $credentials),
    ]);
}

test('SyncSqlSourceJob writes Document with title/body row format and dispatches IndexDocumentJob', function () {
    Queue::fake();

    $source = makeSqlSource();

    $fakeConnector = mock(SqlConnector::class);
    $fakeConnector->shouldReceive('run')
        ->once()
        ->andReturn([
            ['id' => '1', 'title' => 'Welcome', 'body' => 'Hello world'],
            ['id' => '2', 'title' => 'Pricing', 'body' => 'Plans start at $49'],
        ]);

    (new SyncSqlSourceJob($source->id))->handle($fakeConnector);

    $source->refresh();
    expect($source->status)->toBe('indexed');
    expect($source->error)->toBeNull();
    expect($source->last_synced_at)->not->toBeNull();

    $doc = Document::query()->where('source_id', $source->id)->firstOrFail();
    expect($doc->title)->toBe('Test SQL');
    expect($doc->url)->toBe('sql://mysql/d');
    expect($doc->content_hash)->not->toBeNull();

    Queue::assertPushed(IndexDocumentJob::class, function ($job) {
        return str_contains($job->text, 'Welcome: Hello world')
            && str_contains($job->text, 'Pricing: Plans start at $49');
    });
});

test('SyncSqlSourceJob short-circuits when content hash unchanged', function () {
    Queue::fake();

    $source = makeSqlSource();

    $fake = mock(SqlConnector::class);
    $fake->shouldReceive('run')->twice()
        ->andReturn([['id' => '1', 'title' => 'A', 'body' => 'X']]);

    (new SyncSqlSourceJob($source->id))->handle($fake);
    Queue::assertPushed(IndexDocumentJob::class, 1);

    // Second run with same payload — no new IndexDocumentJob, no new Document.
    Queue::fake();
    (new SyncSqlSourceJob($source->id))->handle($fake);
    Queue::assertNothingPushed();
    expect(Document::query()->where('source_id', $source->id)->count())->toBe(1);
});

test('SyncSqlSourceJob marks source failed when connector throws', function () {
    Queue::fake();

    $source = makeSqlSource();

    $fake = mock(SqlConnector::class);
    $fake->shouldReceive('run')
        ->once()
        ->andThrow(new RuntimeException('SQL connect failed: timeout'));

    (new SyncSqlSourceJob($source->id))->handle($fake);

    $source->refresh();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('SQL connect failed');
    Queue::assertNothingPushed();
});

test('SyncSqlSourceJob marks source failed when query returns no rows', function () {
    Queue::fake();

    $source = makeSqlSource();

    $fake = mock(SqlConnector::class);
    $fake->shouldReceive('run')->once()->andReturn([]);

    (new SyncSqlSourceJob($source->id))->handle($fake);

    $source->refresh();
    expect($source->status)->toBe('failed');
    expect($source->error)->toBe('Query returned no rows.');
    Queue::assertNothingPushed();
});

test('SyncSqlSourceJob noops gracefully when source row was deleted', function () {
    Queue::fake();

    $fake = mock(SqlConnector::class);
    $fake->shouldNotReceive('run');

    // Non-existent UUID
    (new SyncSqlSourceJob('019e2000-0000-7000-0000-000000000000'))->handle($fake);

    Queue::assertNothingPushed();
});
