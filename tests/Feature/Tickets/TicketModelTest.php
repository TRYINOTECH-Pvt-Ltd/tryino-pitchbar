<?php

use App\Models\Agent;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Policies\TicketPolicy;
use App\Support\CurrentWorkspace;

function ticketMember(?Workspace $workspace = null, string $role = 'owner'): array
{
    $user = User::factory()->create();
    $workspace = $workspace ?? Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace];
}

test('Ticket persists with all the v2.0.0 columns + casts metadata', function () {
    ['workspace' => $workspace] = ticketMember();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $ticket = Ticket::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'subject' => 'Refund request',
        'priority' => Ticket::PRIORITY_HIGH,
        'metadata' => ['source' => 'chat', 'gross_cents' => 4900],
    ]);

    $fresh = Ticket::query()->withoutWorkspaceScope()->find($ticket->id);
    expect($fresh->subject)->toBe('Refund request');
    expect($fresh->priority)->toBe(Ticket::PRIORITY_HIGH);
    expect($fresh->status)->toBe(Ticket::STATUS_OPEN);
    expect($fresh->metadata['source'])->toBe('chat');
    expect($fresh->metadata['gross_cents'])->toBe(4900);
});

test('markResolved flips status + stamps resolved_at + records the resolver', function () {
    ['user' => $resolver, 'workspace' => $workspace] = ticketMember();
    $ticket = Ticket::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    expect($ticket->status)->toBe(Ticket::STATUS_OPEN);
    expect($ticket->resolved_at)->toBeNull();

    $ticket->markResolved($resolver);
    $ticket->refresh();

    expect($ticket->status)->toBe(Ticket::STATUS_RESOLVED);
    expect($ticket->resolved_at)->not->toBeNull();
    expect($ticket->assigned_to_user_id)->toBe($resolver->id);
});

test('close flips status to closed', function () {
    ['workspace' => $workspace] = ticketMember();
    $ticket = Ticket::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $ticket->close();
    $ticket->refresh();

    expect($ticket->status)->toBe(Ticket::STATUS_CLOSED);
});

test('Tenancy: workspace A members cannot see workspace B tickets via the scoped query', function () {
    ['workspace' => $alpha] = ticketMember();
    ['workspace' => $bravo] = ticketMember();

    $alphaTicket = Ticket::factory()->create(['workspace_id' => $alpha->id]);
    $bravoTicket = Ticket::factory()->create(['workspace_id' => $bravo->id]);

    // Pin CurrentWorkspace to alpha and query — the global scope
    // must filter bravo's row out.
    app(CurrentWorkspace::class)->set($alpha->id);

    $ids = Ticket::query()->pluck('id')->all();
    expect($ids)->toContain($alphaTicket->id);
    expect($ids)->not->toContain($bravoTicket->id);

    app(CurrentWorkspace::class)->clear();
});

test('TicketPolicy: owner + admin + editor can update; viewer cannot', function () {
    $workspace = Workspace::factory()->create();
    $ticket = Ticket::factory()->create(['workspace_id' => $workspace->id]);

    ['user' => $owner] = ticketMember($workspace, 'owner');
    ['user' => $admin] = ticketMember($workspace, 'admin');
    ['user' => $editor] = ticketMember($workspace, 'editor');
    ['user' => $viewer] = ticketMember($workspace, 'viewer');

    $policy = new TicketPolicy;
    expect($policy->update($owner, $ticket))->toBeTrue();
    expect($policy->update($admin, $ticket))->toBeTrue();
    expect($policy->update($editor, $ticket))->toBeTrue();
    expect($policy->update($viewer, $ticket))->toBeFalse();
});

test('TicketPolicy: delete is Owner + Admin only', function () {
    $workspace = Workspace::factory()->create();
    $ticket = Ticket::factory()->create(['workspace_id' => $workspace->id]);

    ['user' => $owner] = ticketMember($workspace, 'owner');
    ['user' => $admin] = ticketMember($workspace, 'admin');
    ['user' => $editor] = ticketMember($workspace, 'editor');

    $policy = new TicketPolicy;
    expect($policy->delete($owner, $ticket))->toBeTrue();
    expect($policy->delete($admin, $ticket))->toBeTrue();
    expect($policy->delete($editor, $ticket))->toBeFalse();
});
