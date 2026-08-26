<?php

use App\Services\Board\BoardStore;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

test('board:add appends a card with body + status + labels', function () {
    $this->artisan('board:add', [
        'title' => 'Pre-chat lead gate',
        '--body' => 'Buyer pagenet asks for…',
        '--status' => 'backlog',
        '--label' => ['widget', 'leads'],
    ])->assertExitCode(0);

    $tasks = app(BoardStore::class)->all();
    expect($tasks)->toHaveCount(1);
    expect($tasks[0]['title'])->toBe('Pre-chat lead gate');
    expect($tasks[0]['body'])->toBe('Buyer pagenet asks for…');
    expect($tasks[0]['status'])->toBe('backlog');
    expect($tasks[0]['labels'])->toBe(['widget', 'leads']);
    expect($tasks[0]['position'])->toBe(1);
});

test('board:add defaults to backlog with no labels', function () {
    $this->artisan('board:add', [
        'title' => 'Tiny housekeeping task',
    ])->assertExitCode(0);

    $task = app(BoardStore::class)->all()[0];
    expect($task['status'])->toBe('backlog');
    expect($task['labels'])->toBe([]);
    expect($task['body'])->toBeNull();
});

test('board:add rejects an unknown status', function () {
    $this->artisan('board:add', [
        'title' => 'Bad status',
        '--status' => 'reviewing',
    ])->assertExitCode(1);

    expect(app(BoardStore::class)->all())->toBe([]);
});

test('board:add bails idempotently on a duplicate title', function () {
    $this->artisan('board:add', ['title' => 'Same'])->assertExitCode(0);
    $this->artisan('board:add', ['title' => 'Same'])
        ->expectsOutputToContain('already on the board')
        ->assertExitCode(0);

    expect(count(app(BoardStore::class)->all()))->toBe(1);
});

test('board:add positions correctly when adding after an existing card in the same column', function () {
    $this->artisan('board:add', ['title' => 'A', '--status' => 'doing'])->assertExitCode(0);
    $this->artisan('board:add', ['title' => 'B', '--status' => 'doing'])->assertExitCode(0);
    $this->artisan('board:add', ['title' => 'C', '--status' => 'backlog'])->assertExitCode(0);

    $tasks = collect(app(BoardStore::class)->all())->keyBy('title');
    expect($tasks['A']['position'])->toBe(1);
    expect($tasks['B']['position'])->toBe(2);
    expect($tasks['C']['position'])->toBe(1);
});
