<?php

use App\Models\AppSetting;
use Illuminate\Support\Facades\Process;

/**
 * Card #490. `QueueTickController` used to run the queue worker with
 * `Artisan::call`, i.e. INSIDE the FrankenPHP HTTP worker. A queue
 * worker tears the application container down when it stops, and the
 * reused Octane worker then failed to resolve `config` on its next
 * request:
 *
 *   Uncaught ReflectionException: Class "config" does not exist
 *   Next BindingResolutionException: Target class [config] does not exist
 *
 * From then on that worker answered 500 to everything, /widget/init
 * included, until it recycled — the recurring all-agent canary bursts on
 * blengi, with nothing in laravel.log because the fatal lands in the
 * worker's own stdout.
 */
function tickToken(): string
{
    $setting = AppSetting::singleton();
    $setting->forceFill(['internal_queue_token' => 'test-tick-token'])->save();

    return 'test-tick-token';
}

test('the worker runs in a subprocess, never inside this process', function () {
    $token = tickToken();
    Process::fake([
        '*' => Process::result(output: '{"processed":3,"failed_in_tick":0,"remaining_pending":0,"failed_total":0,"elapsed_s":1.5}'),
    ]);

    $this->withHeaders(['X-Pitchbar-Token' => $token])
        ->postJson('/api/v1/internal/queue-tick')
        ->assertOk();

    Process::assertRan(function ($process) {
        // The command must be spawned, with its own PHP process — this is
        // the entire fix.
        $command = (array) $process->command;

        // The binary must be a real, runnable php CLI. PHP_BINARY is wrong
        // outside the CLI SAPI — it names php-fpm under FPM and the
        // frankenphp binary under Octane, neither of which can run artisan
        // — so the controller resolves via PhpExecutableFinder instead.
        $binary = (string) ($command[0] ?? '');

        return $binary !== ''
            && is_executable($binary)
            && in_array('artisan', $command, true)
            && collect($command)->contains(fn ($p) => str_contains((string) $p, 'pitchbar:queue-tick'));
    });
});

test('it reports the subprocess stats exactly as before', function () {
    $token = tickToken();
    Process::fake([
        '*' => Process::result(output: "some worker chatter\n{\"processed\":7,\"failed_in_tick\":1,\"remaining_pending\":2,\"failed_total\":4,\"elapsed_s\":2}"),
    ]);

    $this->withHeaders(['X-Pitchbar-Token' => $token])
        ->postJson('/api/v1/internal/queue-tick')
        ->assertOk()
        ->assertJsonPath('data.stats.processed', 7)
        ->assertJsonPath('data.stats.failed_in_tick', 1)
        ->assertJsonPath('data.exit_code', 0);
});

test('a stuck subprocess is reported, not thrown as a 500', function () {
    $token = tickToken();
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'killed', exitCode: 1),
    ]);

    $this->withHeaders(['X-Pitchbar-Token' => $token])
        ->postJson('/api/v1/internal/queue-tick')
        ->assertOk();
});

test('an unauthenticated tick never spawns anything', function () {
    tickToken();
    Process::fake();

    $this->withHeaders(['X-Pitchbar-Token' => 'wrong'])
        ->postJson('/api/v1/internal/queue-tick')
        ->assertStatus(401);

    Process::assertNothingRan();
});
