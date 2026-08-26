<?php

use App\Jobs\Crawl\CrawlSourceJob;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

test('marketing /start stashes the domain in session', function () {
    $response = $this->post('/marketing/start', ['domain' => 'https://example.com']);

    $response->assertRedirect();
    expect(session('marketing.start_domain'))->toBe('https://example.com');
});

test('registering with marketing.start_domain in session creates an Agent + Source and dispatches the crawl', function () {
    Bus::fake();

    $this->withSession(['marketing.start_domain' => 'https://example.com'])
        ->post('/register', [
            'name' => 'Marketing Lead',
            'email' => 'marketing-lead@example.com',
            'password' => 'password-1234',
            'password_confirmation' => 'password-1234',
        ])
        ->assertRedirect();

    /** @var User $user */
    $user = User::where('email', 'marketing-lead@example.com')->firstOrFail();
    $workspace = $user->workspaces()->first();
    expect($workspace)->not->toBeNull();

    $agent = $workspace->agents()->first();
    expect($agent)->not->toBeNull();
    expect($agent->allowed_origins)->toContain('https://example.com');

    $source = $agent->sources()->first();
    expect($source)->not->toBeNull();
    expect($source->config['url'])->toBe('https://example.com');

    Bus::assertDispatched(CrawlSourceJob::class);
});

test('registering without a stashed domain creates a workspace but no agent yet', function () {
    Bus::fake();

    $this->post('/register', [
        'name' => 'Plain User',
        'email' => 'plain@example.com',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ])->assertRedirect();

    $user = User::where('email', 'plain@example.com')->firstOrFail();
    expect($user->workspaces()->first()->agents()->count())->toBe(0);
    Bus::assertNotDispatched(CrawlSourceJob::class);
});
