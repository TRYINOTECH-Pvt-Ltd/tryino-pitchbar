<?php

use App\Services\Board\BoardStore;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

// ─── board:done ─────────────────────────────────────────────────────────

test('board:done moves the card + appends a "How to test from UI" plan', function () {
    $this->artisan('board:add', [
        'title' => 'Add Paddle gateway',
        '--body' => 'EU/UK markets prefer Paddle.',
        '--status' => 'doing',
    ])->assertExitCode(0);

    $this->artisan('board:done', [
        'ref' => 'Add Paddle gateway',
        '--test' => "1. Sign in as super_admin\n2. Open /admin/plans\n3. Sync price",
    ])->assertExitCode(0);

    $task = collect(app(BoardStore::class)->all())->firstWhere('title', 'Add Paddle gateway');
    expect($task['status'])->toBe('done');
    expect($task['body'])->toContain('EU/UK markets prefer Paddle.');
    expect($task['body'])->toContain('## How to test from UI');
    expect($task['body'])->toContain('1. Sign in as super_admin');
    expect($task['body'])->toContain('3. Sync price');
});

test('board:done refuses to close a card without a --test plan', function () {
    $this->artisan('board:add', ['title' => 'Some shipped thing'])->assertExitCode(0);

    $this->artisan('board:done', ['ref' => 'Some shipped thing'])
        ->expectsOutputToContain('--test is required')
        ->assertExitCode(1);

    $task = collect(app(BoardStore::class)->all())->firstWhere('title', 'Some shipped thing');
    expect($task['status'])->toBe('backlog');
});

test('board:done returns an error when no card matches the ref', function () {
    $this->artisan('board:done', [
        'ref' => 'Nothing here',
        '--test' => 'whatever',
    ])
        ->expectsOutputToContain('No card found')
        ->assertExitCode(1);
});

test('board:done is idempotent — re-running replaces the test plan, not duplicates it', function () {
    $this->artisan('board:add', ['title' => 'Card', '--body' => 'Original context.'])->assertExitCode(0);

    $this->artisan('board:done', [
        'ref' => 'Card',
        '--test' => 'first plan',
    ])->assertExitCode(0);

    $this->artisan('board:done', [
        'ref' => 'Card',
        '--test' => 'second plan',
    ])->assertExitCode(0);

    $task = collect(app(BoardStore::class)->all())->firstWhere('title', 'Card');
    expect($task['body'])->toContain('Original context.');
    expect($task['body'])->toContain('second plan');
    expect($task['body'])->not->toContain('first plan');
    // Heading appears exactly once.
    expect(substr_count($task['body'], '## How to test from UI'))->toBe(1);
});

test('board:done lands the card at the bottom of the Done column', function () {
    $this->artisan('board:add', ['title' => 'Old', '--status' => 'done'])->assertExitCode(0);
    $this->artisan('board:add', ['title' => 'New backlog'])->assertExitCode(0);

    $this->artisan('board:done', [
        'ref' => 'New backlog',
        '--test' => 'check',
    ])->assertExitCode(0);

    $tasks = collect(app(BoardStore::class)->all());
    $old = $tasks->firstWhere('title', 'Old');
    $new = $tasks->firstWhere('title', 'New backlog');

    expect($old['position'])->toBe(1);
    expect($new['position'])->toBe(2);
});

// ─── board:start ────────────────────────────────────────────────────────

test('board:start moves a backlog card to doing', function () {
    $this->artisan('board:add', ['title' => 'Pick me up'])->assertExitCode(0);

    $this->artisan('board:start', ['ref' => 'Pick me up'])->assertExitCode(0);

    $task = collect(app(BoardStore::class)->all())->firstWhere('title', 'Pick me up');
    expect($task['status'])->toBe('doing');
    expect($task['position'])->toBe(1);
});

test('board:start is idempotent on already-doing cards', function () {
    $this->artisan('board:add', ['title' => 'Already', '--status' => 'doing'])->assertExitCode(0);

    $this->artisan('board:start', ['ref' => 'Already'])
        ->expectsOutputToContain('Already in progress')
        ->assertExitCode(0);
});

test('board:start errors when ref is unknown', function () {
    $this->artisan('board:start', ['ref' => 'Phantom'])
        ->expectsOutputToContain('No card found')
        ->assertExitCode(1);
});

// ─── ticket numbers (#N) ────────────────────────────────────────────────

test('board:add assigns a sequential ticket number starting at 1', function () {
    $this->artisan('board:add', ['title' => 'First'])->assertExitCode(0);
    $this->artisan('board:add', ['title' => 'Second'])->assertExitCode(0);
    $this->artisan('board:add', ['title' => 'Third'])->assertExitCode(0);

    $tasks = collect(app(BoardStore::class)->all());
    expect($tasks->firstWhere('title', 'First')['number'])->toBe(1);
    expect($tasks->firstWhere('title', 'Second')['number'])->toBe(2);
    expect($tasks->firstWhere('title', 'Third')['number'])->toBe(3);
});

test('numbers never reuse — archiving #2 still gives #4 to the next add', function () {
    $this->artisan('board:add', ['title' => 'A'])->assertExitCode(0);
    $this->artisan('board:add', ['title' => 'B'])->assertExitCode(0);
    $this->artisan('board:add', ['title' => 'C'])->assertExitCode(0);

    // Pretend B was archived by deleting it from the file directly.
    $store = app(BoardStore::class);
    $tasks = $store->all();
    $tasks = array_values(array_filter($tasks, fn ($t) => $t['title'] !== 'B'));
    $store->replace($tasks);

    $this->artisan('board:add', ['title' => 'D'])->assertExitCode(0);
    $d = collect(app(BoardStore::class)->all())->firstWhere('title', 'D');
    expect($d['number'])->toBe(4);
});

test('board:start accepts a number reference like 14 or #14', function () {
    $this->artisan('board:add', ['title' => 'Numbered card'])->assertExitCode(0);
    $number = app(BoardStore::class)->all()[0]['number'];

    $this->artisan('board:start', ['ref' => (string) $number])->assertExitCode(0);
    $task = collect(app(BoardStore::class)->all())->firstWhere('title', 'Numbered card');
    expect($task['status'])->toBe('doing');

    // And it works with the '#' prefix too.
    $this->artisan('board:add', ['title' => 'Hash card'])->assertExitCode(0);
    $hashNumber = collect(app(BoardStore::class)->all())->firstWhere('title', 'Hash card')['number'];
    $this->artisan('board:start', ['ref' => "#{$hashNumber}"])->assertExitCode(0);
    $hash = collect(app(BoardStore::class)->all())->firstWhere('title', 'Hash card');
    expect($hash['status'])->toBe('doing');
});

test('board:done accepts a number reference', function () {
    $this->artisan('board:add', ['title' => 'Closeable'])->assertExitCode(0);
    $number = app(BoardStore::class)->all()[0]['number'];

    $this->artisan('board:done', [
        'ref' => (string) $number,
        '--test' => 'click around',
    ])->assertExitCode(0);

    $task = collect(app(BoardStore::class)->all())->firstWhere('title', 'Closeable');
    expect($task['status'])->toBe('done');
    expect($task['body'])->toContain('## How to test from UI');
});

test('BoardStore backfills numbers on legacy cards in created_at order on first read', function () {
    // Write a file by hand, simulating a board from before numbers existed.
    Storage::disk('local')->put(BoardStore::FILENAME, json_encode([
        ['id' => 'a', 'title' => 'Older', 'status' => 'backlog', 'position' => 1, 'created_at' => '2026-01-01T00:00:00+00:00'],
        ['id' => 'b', 'title' => 'Newer', 'status' => 'backlog', 'position' => 2, 'created_at' => '2026-02-01T00:00:00+00:00'],
    ]));

    $tasks = app(BoardStore::class)->all();
    $older = collect($tasks)->firstWhere('title', 'Older');
    $newer = collect($tasks)->firstWhere('title', 'Newer');

    expect($older['number'])->toBe(1);
    expect($newer['number'])->toBe(2);

    // And the next add picks up at 3.
    $this->artisan('board:add', ['title' => 'Third'])->assertExitCode(0);
    $third = collect(app(BoardStore::class)->all())->firstWhere('title', 'Third');
    expect($third['number'])->toBe(3);
});

test('BoardStore backfills numbers without re-numbering existing assignments', function () {
    // Mixed file: some cards already have numbers, some don't.
    Storage::disk('local')->put(BoardStore::FILENAME, json_encode([
        ['id' => 'a', 'number' => 5, 'title' => 'Has 5', 'status' => 'backlog', 'created_at' => '2026-01-01T00:00:00+00:00'],
        ['id' => 'b', 'title' => 'No number', 'status' => 'backlog', 'created_at' => '2026-02-01T00:00:00+00:00'],
    ]));

    $tasks = app(BoardStore::class)->all();
    $hasFive = collect($tasks)->firstWhere('title', 'Has 5');
    $noNumber = collect($tasks)->firstWhere('title', 'No number');

    // Existing #5 stays at #5; the new one fills #6 (max+1).
    expect($hasFive['number'])->toBe(5);
    expect($noNumber['number'])->toBe(6);
});

// ─── --release flag + board:stamp-version ──────────────────────────────

test('board:done --release stamps the release tag onto the card', function () {
    $this->artisan('board:add', [
        'title' => 'Position picker',
        '--status' => 'doing',
    ])->assertExitCode(0);

    $this->artisan('board:done', [
        'ref' => 'Position picker',
        '--test' => '1. Open customize. 2. Pick bottom-right.',
        '--release' => 'v1.1.0',
    ])->assertExitCode(0);

    $task = collect(app(BoardStore::class)->all())->firstWhere('title', 'Position picker');
    expect($task['version'])->toBe('v1.1.0');
});

test('board:stamp-version bulk-stamps every card in a status without a version', function () {
    $this->artisan('board:add', ['title' => 'A', '--status' => 'doing'])->assertExitCode(0);
    $this->artisan('board:add', ['title' => 'B', '--status' => 'doing'])->assertExitCode(0);
    $this->artisan('board:done', ['ref' => 'A', '--test' => 'x'])->assertExitCode(0);
    $this->artisan('board:done', ['ref' => 'B', '--test' => 'x'])->assertExitCode(0);

    $this->artisan('board:stamp-version', [
        'version' => 'v1.1.0',
        '--status' => 'done',
    ])->assertExitCode(0);

    foreach (app(BoardStore::class)->all() as $task) {
        expect($task['version'])->toBe('v1.1.0');
    }
});

test('board:stamp-version is idempotent — does not overwrite a prior tag without --force', function () {
    $this->artisan('board:add', ['title' => 'Already labeled', '--status' => 'done'])->assertExitCode(0);

    // Pre-stamp manually via store so the test is independent of board:done internals.
    $store = app(BoardStore::class);
    $tasks = $store->all();
    $i = collect($tasks)->search(fn ($t) => ($t['title'] ?? '') === 'Already labeled');
    $tasks[$i]['version'] = 'v1.0.0';
    $store->replace($tasks);

    $this->artisan('board:stamp-version', [
        'version' => 'v1.1.0',
        '--status' => 'done',
    ])->assertExitCode(0);

    $task = collect($store->all())->firstWhere('title', 'Already labeled');
    expect($task['version'])->toBe('v1.0.0');

    // --force overrides.
    $this->artisan('board:stamp-version', [
        'version' => 'v1.1.0',
        '--status' => 'done',
        '--force' => true,
    ])->assertExitCode(0);

    $task = collect($store->all())->firstWhere('title', 'Already labeled');
    expect($task['version'])->toBe('v1.1.0');
});
