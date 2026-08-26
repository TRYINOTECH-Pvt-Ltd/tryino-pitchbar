<?php

use App\Events\Concerns\BroadcastsWhenConfigured;
use App\Events\Conversations\AgentReplyPostedEvent;
use App\Events\Conversations\ConversationClaimedEvent;
use App\Events\Conversations\HumanRequestedEvent;
use App\Events\Leads\LeadCapturedEvent;
use App\Events\TokenStreamed;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;

dataset('broadcast_event_classes', [
    [AgentReplyPostedEvent::class],
    [ConversationClaimedEvent::class],
    [HumanRequestedEvent::class],
    [LeadCapturedEvent::class],
    [TokenStreamed::class],
    [TurnCompleted::class],
    [TurnFailed::class],
]);

test('trait short-circuits broadcasting when driver is log', function () {
    config()->set('broadcasting.default', 'log');

    $event = newEventOf(AgentReplyPostedEvent::class);

    expect($event->broadcastWhen())->toBeFalse();
});

test('trait short-circuits broadcasting when driver is null', function () {
    config()->set('broadcasting.default', 'null');

    $event = newEventOf(AgentReplyPostedEvent::class);

    expect($event->broadcastWhen())->toBeFalse();
});

test('trait short-circuits broadcasting when driver config is missing', function () {
    config()->set('broadcasting.default', null);

    $event = newEventOf(AgentReplyPostedEvent::class);

    expect($event->broadcastWhen())->toBeFalse();
});

test('trait allows broadcasting when driver is reverb', function () {
    config()->set('broadcasting.default', 'reverb');

    $event = newEventOf(AgentReplyPostedEvent::class);

    expect($event->broadcastWhen())->toBeTrue();
});

test('trait allows broadcasting when driver is pusher', function () {
    config()->set('broadcasting.default', 'pusher');

    $event = newEventOf(AgentReplyPostedEvent::class);

    expect($event->broadcastWhen())->toBeTrue();
});

test('every ShouldBroadcast event uses the BroadcastsWhenConfigured trait', function (string $class) {
    $reflection = new ReflectionClass($class);
    $traits = collect($reflection->getTraitNames());

    expect($traits)->toContain(BroadcastsWhenConfigured::class);
})->with('broadcast_event_classes');

test('all 7 events return false on log driver', function (string $class) {
    config()->set('broadcasting.default', 'log');

    $event = newEventOf($class);

    expect($event->broadcastWhen())->toBeFalse();
})->with('broadcast_event_classes');

/**
 * Instantiate any of the 7 ShouldBroadcast event classes with a
 * minimal stub payload — we only need a live object to call
 * broadcastWhen() on.
 */
function newEventOf(string $class): object
{
    return match ($class) {
        AgentReplyPostedEvent::class => new $class('cid', 'mid', 'hello', 'Agent'),
        ConversationClaimedEvent::class => new $class('cid', 'uid', 'User Name'),
        HumanRequestedEvent::class => new $class('cid', 'aid'),
        LeadCapturedEvent::class => new $class('wsid', 'lid', 'a@b.test', null, null, 'Agent', '/app/inbox/lid'),
        TokenStreamed::class => new $class('cid', 'mid', 'tok'),
        TurnCompleted::class => new $class('cid', 'mid', 'text'),
        TurnFailed::class => new $class('cid', 'oops'),
    };
}
