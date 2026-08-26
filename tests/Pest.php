<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Widget tests run through the same VerifyWidgetOrigin middleware
// production traffic does, so every request needs an Origin header
// that matches the agent's allowed_origins. Test agents default to
// `['https://example.com']` (see database/factories/AgentFactory.php).
// Setting it via beforeEach keeps the 40+ pre-existing tests green
// without each having to spell out the same header.
//
// Specific tests that need a different Origin (e.g.
// VerifyWidgetOriginTest exercising the rejection branches) just
// override per-call by passing an explicit `Origin` header to
// `postJson`/`getJson`, which Laravel merges over the default.
pest()->beforeEach(function () {
    $this->withHeaders(['Origin' => 'https://example.com']);
})->in('Feature/Widget', 'Feature/Leads');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/*
|--------------------------------------------------------------------------
| Tenancy fixtures
|--------------------------------------------------------------------------
|
| Most feature tests need a user → workspace → workspace_user pivot, and
| often an Agent attached to that workspace. Before this consolidation
| every test file declared its own `asAdmin()` / `notionUser()` /
| `googleWorkspace()` and we periodically had to rename them to dodge
| Pest's "function already declared" autoload collisions.
|
| Use these instead. Each returns the bag the caller actually needs;
| any extra rows (Agent) are seeded with sensible defaults but you can
| pass overrides.
|
*/

/**
 * Creates a User + Workspace + WorkspaceUser pivot in one shot.
 *
 * @param  array{role?: string, accepted?: bool, owner?: bool}  $opts
 * @return array{user: User, workspace: Workspace}
 */
function workspaceMember(array $opts = []): array
{
    $role = $opts['role'] ?? 'admin';
    $accepted = $opts['accepted'] ?? true;
    $owner = $opts['owner'] ?? true;

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create($owner ? ['owner_user_id' => $user->id] : []);

    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => $accepted ? now() : null,
    ]);

    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace];
}

/**
 * Same as workspaceMember(), plus an Agent attached.
 *
 * @param  array<string, mixed>  $opts  passes through to workspaceMember + agentOverrides
 * @return array{user: User, workspace: Workspace, agent: Agent}
 */
function workspaceMemberWithAgent(array $opts = [], array $agentOverrides = []): array
{
    $bag = workspaceMember($opts);
    $bag['agent'] = Agent::factory()->create([
        'workspace_id' => $bag['workspace']->id,
        ...$agentOverrides,
    ]);

    return $bag;
}

/**
 * Adds an extra user as a member of an existing workspace.
 */
function addMember(Workspace $workspace, User $user, string $role = 'admin', bool $accepted = true): void
{
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => $accepted ? now() : null,
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();
}
