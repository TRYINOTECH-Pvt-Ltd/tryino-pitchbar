<?php

use App\Enums\PlatformRole;
use App\Models\User;
use App\Services\Board\BoardStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Fake the local disk so tests get an isolated, ephemeral file
    // for the board JSON. The BoardStore default disk is 'local' so
    // the rest of the wiring stays unchanged.
    Storage::fake('local');
});

function superAdminForBoard(): User
{
    return User::factory()->create(['role' => PlatformRole::SuperAdmin]);
}

test('GET /admin/board renders for super_admin', function () {
    $admin = superAdminForBoard();

    $this->actingAs($admin)->get('/admin/board')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('admin/board')
            ->has('tasks')
            ->has('statuses', 4)
            ->where('statuses.0', 'backlog')
            ->where('statuses.3', 'done'));
});

test('a customer cannot reach /admin/board (404 hides existence)', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);

    $this->actingAs($customer)->get('/admin/board')->assertStatus(404);
});

test('an unauthenticated visitor is redirected to login', function () {
    $this->get('/admin/board')->assertRedirect('/login');
});

test('POST /admin/board appends a task with title + status + position', function () {
    $admin = superAdminForBoard();

    $this->actingAs($admin)->post('/admin/board', [
        'title' => 'Add Paddle gateway',
        'body' => 'EU/UK markets prefer it.',
        'status' => 'backlog',
        'labels' => ['billing', 'eu'],
    ])->assertRedirect('/admin/board');

    $tasks = app(BoardStore::class)->all();
    expect($tasks)->toHaveCount(1);
    expect($tasks[0]['title'])->toBe('Add Paddle gateway');
    expect($tasks[0]['status'])->toBe('backlog');
    expect($tasks[0]['position'])->toBe(1);
    expect($tasks[0]['labels'])->toBe(['billing', 'eu']);
    expect($tasks[0]['id'])->toBeString();
});

test('POST /admin/board rejects unknown status', function () {
    $admin = superAdminForBoard();

    $this->actingAs($admin)->post('/admin/board', [
        'title' => 'whatever',
        'status' => 'reviewing',
    ])->assertSessionHasErrors('status');
});

test('PATCH /admin/board/{taskId} edits title + body + labels', function () {
    $admin = superAdminForBoard();
    $this->actingAs($admin)->post('/admin/board', [
        'title' => 'Old',
        'status' => 'backlog',
    ])->assertRedirect();

    $taskId = app(BoardStore::class)->all()[0]['id'];

    $this->actingAs($admin)->patch("/admin/board/{$taskId}", [
        'title' => 'New title',
        'body' => 'New body',
        'status' => 'backlog',
        'labels' => ['ops'],
    ])->assertRedirect('/admin/board');

    $tasks = app(BoardStore::class)->all();
    expect($tasks[0]['title'])->toBe('New title');
    expect($tasks[0]['body'])->toBe('New body');
    expect($tasks[0]['labels'])->toBe(['ops']);
});

test('PATCH moves a task to a new column and resets its position', function () {
    $admin = superAdminForBoard();
    // Two backlog tasks, then one in doing.
    $this->actingAs($admin)->post('/admin/board', ['title' => 'A', 'status' => 'backlog'])->assertRedirect();
    $this->actingAs($admin)->post('/admin/board', ['title' => 'B', 'status' => 'backlog'])->assertRedirect();
    $this->actingAs($admin)->post('/admin/board', ['title' => 'D1', 'status' => 'doing'])->assertRedirect();

    $tasks = app(BoardStore::class)->all();
    $a = collect($tasks)->firstWhere('title', 'A');

    // Move A → doing.
    $this->actingAs($admin)->patch("/admin/board/{$a['id']}", [
        'title' => 'A',
        'status' => 'doing',
    ])->assertRedirect();

    $tasksAfter = app(BoardStore::class)->all();
    $aAfter = collect($tasksAfter)->firstWhere('title', 'A');
    $d1After = collect($tasksAfter)->firstWhere('title', 'D1');

    expect($aAfter['status'])->toBe('doing');
    // Position resets to "max position in doing + 1" when moving columns.
    expect($aAfter['position'])->toBe(2);
    expect($d1After['position'])->toBe(1);
});

test('PATCH on an unknown task id returns 404', function () {
    $admin = superAdminForBoard();
    $this->actingAs($admin)->patch('/admin/board/does-not-exist', [
        'title' => 'x',
    ])->assertNotFound();
});

test('DELETE /admin/board/{taskId} archives the task', function () {
    $admin = superAdminForBoard();
    $this->actingAs($admin)->post('/admin/board', ['title' => 'Z', 'status' => 'backlog'])->assertRedirect();

    $taskId = app(BoardStore::class)->all()[0]['id'];

    $this->actingAs($admin)->delete("/admin/board/{$taskId}")->assertRedirect();

    expect(app(BoardStore::class)->all())->toBe([]);
});

test('a customer cannot store/update/destroy', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);

    $this->actingAs($customer)->post('/admin/board', ['title' => 'sneak'])->assertNotFound();
    $this->actingAs($customer)->patch('/admin/board/whatever', ['title' => 'sneak'])->assertNotFound();
    $this->actingAs($customer)->delete('/admin/board/whatever')->assertNotFound();
});

test('board:seed adds the canned backlog idempotently', function () {
    $this->artisan('board:seed')->assertExitCode(0);
    $first = app(BoardStore::class)->all();
    expect(count($first))->toBeGreaterThan(0);

    $this->artisan('board:seed')->expectsOutputToContain('No new tasks to add')->assertExitCode(0);
    $second = app(BoardStore::class)->all();
    expect(count($second))->toBe(count($first));
});

test('tasks live on the filesystem so a DB reset cannot wipe them', function () {
    $admin = superAdminForBoard();
    $this->actingAs($admin)->post('/admin/board', [
        'title' => 'Survives schema reset',
        'status' => 'backlog',
    ])->assertRedirect();

    // The board JSON lives on the local disk, not in the DB. This is
    // the contract that protects it across `migrate:fresh` during
    // development. SQLite-in-memory can't actually VACUUM mid-test
    // transaction, so we prove the contract structurally:
    //
    //   1. The file is present on the storage disk.
    //   2. Wiping every DB table (the bit migrate:fresh actually resets)
    //      leaves the BoardStore reads untouched.
    expect(Storage::disk('local')->exists(BoardStore::FILENAME))->toBeTrue();

    foreach (['users', 'workspaces', 'agents', 'app_settings'] as $table) {
        DB::table($table)->delete();
    }

    expect(app(BoardStore::class)->all())->toHaveCount(1);
    expect(app(BoardStore::class)->all()[0]['title'])->toBe('Survives schema reset');
});

test('BoardStore returns [] when the file is missing or corrupt', function () {
    expect(app(BoardStore::class)->all())->toBe([]);

    Storage::disk('local')->put(BoardStore::FILENAME, '{ this is not valid json');
    expect(app(BoardStore::class)->all())->toBe([]);
});

test('sortedTasks: backlog ordered by created_at ASC (oldest first)', function () {
    $admin = superAdminForBoard();

    // Three backlog cards with explicit, deliberately-out-of-order
    // created_at — verifying the SORT, not the insert order.
    Storage::disk('local')->put(BoardStore::FILENAME, json_encode([
        ['id' => 'a', 'number' => 1, 'title' => 'Newest', 'status' => 'backlog', 'position' => 99, 'created_at' => '2026-05-09T10:00:00+00:00', 'updated_at' => '2026-05-09T10:00:00+00:00'],
        ['id' => 'b', 'number' => 2, 'title' => 'Oldest', 'status' => 'backlog', 'position' => 1, 'created_at' => '2026-05-01T10:00:00+00:00', 'updated_at' => '2026-05-01T10:00:00+00:00'],
        ['id' => 'c', 'number' => 3, 'title' => 'Middle', 'status' => 'backlog', 'position' => 50, 'created_at' => '2026-05-05T10:00:00+00:00', 'updated_at' => '2026-05-05T10:00:00+00:00'],
    ]));

    $response = $this->actingAs($admin)->get('/admin/board')->assertOk();
    $titles = collect($response->viewData('page')['props']['tasks'])->pluck('title')->toArray();

    expect($titles)->toBe(['Oldest', 'Middle', 'Newest']);
});

test('sortedTasks: done ordered by updated_at DESC (latest-completed first)', function () {
    $admin = superAdminForBoard();

    Storage::disk('local')->put(BoardStore::FILENAME, json_encode([
        ['id' => 'a', 'number' => 1, 'title' => 'Mid-shipped', 'status' => 'done', 'position' => 1, 'created_at' => '2026-04-01T10:00:00+00:00', 'updated_at' => '2026-05-05T10:00:00+00:00'],
        ['id' => 'b', 'number' => 2, 'title' => 'Just-shipped', 'status' => 'done', 'position' => 2, 'created_at' => '2026-04-02T10:00:00+00:00', 'updated_at' => '2026-05-09T10:00:00+00:00'],
        ['id' => 'c', 'number' => 3, 'title' => 'Old-shipped', 'status' => 'done', 'position' => 3, 'created_at' => '2026-04-03T10:00:00+00:00', 'updated_at' => '2026-05-01T10:00:00+00:00'],
    ]));

    $response = $this->actingAs($admin)->get('/admin/board')->assertOk();
    $titles = collect($response->viewData('page')['props']['tasks'])->pluck('title')->toArray();

    expect($titles)->toBe(['Just-shipped', 'Mid-shipped', 'Old-shipped']);
});

test('sortedTasks: doing and review keep manual position ordering', function () {
    $admin = superAdminForBoard();

    Storage::disk('local')->put(BoardStore::FILENAME, json_encode([
        ['id' => 'a', 'number' => 1, 'title' => 'Doing-2nd', 'status' => 'doing', 'position' => 2, 'created_at' => '2026-05-09T10:00:00+00:00', 'updated_at' => '2026-05-09T10:00:00+00:00'],
        ['id' => 'b', 'number' => 2, 'title' => 'Doing-1st', 'status' => 'doing', 'position' => 1, 'created_at' => '2026-05-01T10:00:00+00:00', 'updated_at' => '2026-05-01T10:00:00+00:00'],
        ['id' => 'c', 'number' => 3, 'title' => 'Review-1st', 'status' => 'review', 'position' => 1, 'created_at' => '2026-05-05T10:00:00+00:00', 'updated_at' => '2026-05-05T10:00:00+00:00'],
    ]));

    $response = $this->actingAs($admin)->get('/admin/board')->assertOk();
    $titles = collect($response->viewData('page')['props']['tasks'])->pluck('title')->toArray();

    // Position-based within doing (ignores created_at), then review.
    expect($titles)->toBe(['Doing-1st', 'Doing-2nd', 'Review-1st']);
});
