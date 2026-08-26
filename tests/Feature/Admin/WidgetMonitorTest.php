<?php

use App\Jobs\Widget\RecordWidgetEventJob;
use App\Models\User;
use App\Models\WidgetEvent;
use App\Services\Widget\WidgetEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function widgetMonitorSuperAdmin(): User
{
    return User::factory()->create(['role' => 'super_admin']);
}

it('renders the widget monitor for a super admin', function () {
    WidgetEvent::factory()->create(['type' => 'stream_failed']);

    $this->actingAs(widgetMonitorSuperAdmin())
        ->get('/settings/system/widget-monitor')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/widget-monitor')
            ->has('events', 1)
            ->has('summary')
            ->has('verdict')
        );
});

it('hides the widget monitor from non-super-admins', function () {
    // Default factory role is "customer" — the super_admin middleware
    // existence-hides the route (404, not 403).
    $this->actingAs(User::factory()->create())
        ->get('/settings/system/widget-monitor')
        ->assertNotFound();
});

it('filters the feed to open events only', function () {
    WidgetEvent::factory()->create(['type' => 'stream_failed']);
    WidgetEvent::factory()->resolved()->create(['type' => 'provider_failover']);

    $this->actingAs(widgetMonitorSuperAdmin())
        ->get('/settings/system/widget-monitor?status=open')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('events', 1));
});

it('resolves and reopens an event', function () {
    $event = WidgetEvent::factory()->create();
    $admin = widgetMonitorSuperAdmin();

    $this->actingAs($admin)
        ->post("/settings/system/widget-monitor/{$event->id}/resolve")
        ->assertRedirect();

    $event->refresh();
    expect($event->resolved_at)->not->toBeNull();
    expect($event->resolved_by)->toBe($admin->id);

    // Toggling again re-opens it.
    $this->actingAs($admin)
        ->post("/settings/system/widget-monitor/{$event->id}/resolve")
        ->assertRedirect();

    expect($event->fresh()->resolved_at)->toBeNull();
});

it('dispatches a record job through the recorder', function () {
    Queue::fake();

    app(WidgetEventRecorder::class)->record(
        type: WidgetEventRecorder::TYPE_STREAM_FAILED,
        message: 'boom',
        conversationId: 'conv-123',
    );

    Queue::assertPushed(
        RecordWidgetEventJob::class,
        fn (RecordWidgetEventJob $job): bool => $job->attributes['type'] === 'stream_failed'
            && $job->attributes['conversation_id'] === 'conv-123'
    );
});

it('normalises an empty conversation id to null', function () {
    Queue::fake();

    // The hot path passes (string) ($claims['conversation_id'] ?? '') — an
    // empty string must land as NULL, not "".
    app(WidgetEventRecorder::class)->record(
        type: WidgetEventRecorder::TYPE_PROVIDER_DOWN,
        conversationId: '',
    );

    Queue::assertPushed(
        RecordWidgetEventJob::class,
        fn (RecordWidgetEventJob $job): bool => $job->attributes['conversation_id'] === null
    );
});

it('persists the event when the record job runs', function () {
    (new RecordWidgetEventJob([
        'type' => 'provider_down',
        'severity' => 'error',
        'provider' => 'cloudflare',
        'message' => 'all providers exhausted',
        'context' => ['exception' => 'OpenAiTimeoutException'],
        'occurred_at' => now()->toIso8601String(),
    ]))->handle();

    $event = WidgetEvent::query()->where('type', 'provider_down')->first();
    expect($event)->not->toBeNull();
    expect($event->provider)->toBe('cloudflare');
    expect($event->context)->toBe(['exception' => 'OpenAiTimeoutException']);
});
