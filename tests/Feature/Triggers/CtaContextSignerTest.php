<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Support\CtaContextSigner;

beforeEach(function () {
    $this->signer = new CtaContextSigner;
    $this->workspace = Workspace::factory()->create();
    $this->agent = Agent::factory()->create(['workspace_id' => $this->workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $this->agent->id]);
    $this->conversation = Conversation::factory()->create([
        'agent_id' => $this->agent->id,
        'visitor_id' => $visitor->id,
        'page_url' => 'https://example.com/pricing',
    ]);
});

test('builds a signed URL with the requested fields', function () {
    $url = $this->signer->buildSignedUrl(
        'https://crm.example.com/inbox',
        ['conversation_id', 'page_url', 'agent_id'],
        $this->conversation,
        $this->workspace,
    );

    expect($url)
        ->toStartWith('https://crm.example.com/inbox?pitchbar_ctx=')
        ->toContain('&pitchbar_ts=')
        ->toContain('&pitchbar_sig=');

    [, $query] = explode('?', $url, 2);
    parse_str($query, $params);

    expect($this->signer->verify(
        $params['pitchbar_ctx'],
        $params['pitchbar_ts'],
        $params['pitchbar_sig'],
        $this->workspace->fresh(),
    ))->toBeTrue();

    $decoded = $this->signer->decode($params['pitchbar_ctx']);
    expect($decoded)
        ->toHaveKey('conversation_id', $this->conversation->id)
        ->toHaveKey('page_url', 'https://example.com/pricing')
        ->toHaveKey('agent_id', $this->agent->id)
        ->not->toHaveKey('visitor_email');
});

test('returns the URL untouched when no fields are forwarded', function () {
    $url = $this->signer->buildSignedUrl(
        'https://crm.example.com/inbox',
        [],
        $this->conversation,
        $this->workspace,
    );

    expect($url)->toBe('https://crm.example.com/inbox');
});

test('preserves existing query strings on the base URL', function () {
    $url = $this->signer->buildSignedUrl(
        'https://crm.example.com/inbox?source=widget',
        ['conversation_id'],
        $this->conversation,
        $this->workspace,
    );

    expect($url)
        ->toContain('?source=widget&pitchbar_ctx=')
        ->toContain('&pitchbar_sig=');
});

test('forwards lead fields only when a Lead is attached to the conversation', function () {
    Lead::create([
        'agent_id' => $this->agent->id,
        'conversation_id' => $this->conversation->id,
        'email' => 'buyer@example.com',
        'name' => 'Buyer',
        'fields' => ['company' => 'Acme'],
        'status' => 'new',
    ]);

    $url = $this->signer->buildSignedUrl(
        'https://crm.example.com/inbox',
        ['visitor_email', 'visitor_name', 'captured_fields'],
        $this->conversation,
        $this->workspace,
    );
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $params);

    $payload = $this->signer->decode($params['pitchbar_ctx']);
    expect($payload)
        ->toHaveKey('visitor_email', 'buyer@example.com')
        ->toHaveKey('visitor_name', 'Buyer')
        ->toHaveKey('captured_fields', ['company' => 'Acme']);
});

test('strips sensitive keys from captured_fields', function () {
    Lead::create([
        'agent_id' => $this->agent->id,
        'conversation_id' => $this->conversation->id,
        'email' => 'buyer@example.com',
        'name' => 'Buyer',
        'fields' => [
            'company' => 'Acme',
            'password' => 'should-not-leak',
            'api_token' => 'should-not-leak',
            'private_note' => 'should-not-leak',
        ],
        'status' => 'new',
    ]);

    $url = $this->signer->buildSignedUrl(
        'https://crm.example.com/inbox',
        ['captured_fields'],
        $this->conversation,
        $this->workspace,
    );
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $params);
    $payload = $this->signer->decode($params['pitchbar_ctx']);

    expect($payload['captured_fields'])->toBe(['company' => 'Acme']);
});

test('verify rejects a tampered context payload', function () {
    $url = $this->signer->buildSignedUrl(
        'https://crm.example.com/inbox',
        ['conversation_id'],
        $this->conversation,
        $this->workspace,
    );
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $params);

    // Tamper with the signature. Pick a replacement char that is
    // guaranteed different from the existing first char — using a
    // fixed 'a' was a 1/16 flake when the real sig already started
    // with 'a'.
    $sig = $params['pitchbar_sig'];
    $replacement = $sig[0] === 'a' ? 'b' : 'a';
    expect($this->signer->verify(
        $params['pitchbar_ctx'],
        $params['pitchbar_ts'],
        substr_replace($sig, $replacement, 0, 1),
        $this->workspace->fresh(),
    ))->toBeFalse();
});

test('verify rejects payloads outside the 5-minute replay window', function () {
    $url = $this->signer->buildSignedUrl(
        'https://crm.example.com/inbox',
        ['conversation_id'],
        $this->conversation,
        $this->workspace,
    );
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $params);

    // Pretend the request arrived 10 minutes after signing.
    $stale = (string) ((int) $params['pitchbar_ts'] - 600);
    // Mint a new signature so the failure isolates the timestamp check
    // and we don't conflate it with a generic sig mismatch.
    $rawCtx = $params['pitchbar_ctx'];
    $sig = hash_hmac('sha256', $rawCtx.'.'.$stale, $this->workspace->fresh()->cta_context_secret);

    expect($this->signer->verify($rawCtx, $stale, $sig, $this->workspace->fresh()))->toBeFalse();
});

test('verify rejects when no workspace secret has been generated yet', function () {
    // Build the URL via the signer (which seeds the secret) but verify
    // against a workspace whose secret we then null out.
    $url = $this->signer->buildSignedUrl(
        'https://crm.example.com/inbox',
        ['conversation_id'],
        $this->conversation,
        $this->workspace,
    );
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $params);

    $this->workspace->forceFill(['cta_context_secret' => null])->save();

    expect($this->signer->verify(
        $params['pitchbar_ctx'],
        $params['pitchbar_ts'],
        $params['pitchbar_sig'],
        $this->workspace->fresh(),
    ))->toBeFalse();
});

test('only ALLOWED_FIELDS are forwarded — operator-side filter is enforced', function () {
    $url = $this->signer->buildSignedUrl(
        'https://crm.example.com/inbox',
        ['conversation_id', 'malicious_field', 'admin_role'],
        $this->conversation,
        $this->workspace,
    );
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $params);
    $payload = $this->signer->decode($params['pitchbar_ctx']);

    expect($payload)
        ->toHaveKey('conversation_id')
        ->not->toHaveKey('malicious_field')
        ->not->toHaveKey('admin_role');
});
