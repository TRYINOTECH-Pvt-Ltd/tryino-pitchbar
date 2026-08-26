<?php

use App\Support\OAuthState;
use Illuminate\Http\Request;

function makeRequest(): Request
{
    $req = Request::create('/oauth/x/start');
    $req->setLaravelSession(app('session.store'));

    return $req;
}

test('start writes a 40-char nonce + workspace + issued_at to the session', function () {
    $req = makeRequest();
    $state = OAuthState::start($req, 'notion', 'ws-1');

    expect($state)->toHaveLength(40);
    $stash = $req->session()->get('notion.oauth_state');
    expect($stash)->toMatchArray(['workspace_id' => 'ws-1']);
    expect($stash['state'])->toBe($state);
    expect($stash['issued_at'])->toBeString();
});

test('consume returns workspace_id when state matches and is fresh', function () {
    $req = makeRequest();
    $state = OAuthState::start($req, 'google', 'ws-2');

    $workspaceId = OAuthState::consume($req, 'google', $state);

    expect($workspaceId)->toBe('ws-2');
    // one-shot — second call returns null
    expect(OAuthState::consume($req, 'google', $state))->toBeNull();
});

test('consume returns null when the presented state does not match', function () {
    $req = makeRequest();
    OAuthState::start($req, 'notion', 'ws-1');

    expect(OAuthState::consume($req, 'notion', 'tampered'))->toBeNull();
});

test('consume returns null when state is older than the TTL', function () {
    $req = makeRequest();
    $req->session()->put('notion.oauth_state', [
        'state' => 'abc',
        'workspace_id' => 'ws-old',
        'issued_at' => now()->subMinutes(15)->toIso8601String(),
    ]);

    expect(OAuthState::consume($req, 'notion', 'abc', ttlMinutes: 10))->toBeNull();
});

test('consume returns the workspace_id when state is within TTL', function () {
    $req = makeRequest();
    $req->session()->put('google.oauth_state', [
        'state' => 'abc',
        'workspace_id' => 'ws-fresh',
        'issued_at' => now()->subMinutes(2)->toIso8601String(),
    ]);

    expect(OAuthState::consume($req, 'google', 'abc', ttlMinutes: 10))->toBe('ws-fresh');
});

test('consume returns null when no session entry exists', function () {
    $req = makeRequest();

    expect(OAuthState::consume($req, 'notion', 'anything'))->toBeNull();
});

test('consume gracefully handles back-compat sessions without issued_at', function () {
    $req = makeRequest();
    $req->session()->put('notion.oauth_state', [
        'state' => 'abc',
        'workspace_id' => 'ws-legacy',
    ]);

    expect(OAuthState::consume($req, 'notion', 'abc'))->toBe('ws-legacy');
});
