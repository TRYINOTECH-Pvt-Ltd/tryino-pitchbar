<?php

use App\Models\Agent;
use App\Models\Lead;

/**
 * Per-agent lead-form schema (#34). The admin's customize page
 * stores a list of field definitions on `agents.lead_form_fields`;
 * the widget renders the same list in both the inline lead form
 * and the pre-chat gate. NULL = use the legacy Name + Email shape.
 */
test('PATCH /app/agents/{id} accepts a lead_form_fields schema', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent();

    $schema = [
        ['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => false],
        ['key' => 'email', 'label' => 'Work email', 'type' => 'email', 'required' => true, 'placeholder' => 'name@company.com'],
        ['key' => 'company', 'label' => 'Company', 'type' => 'text', 'required' => true, 'maxlength' => 120],
        ['key' => 'team_size', 'label' => 'Team size', 'type' => 'select', 'options' => ['1-10', '11-50', '51-200', '201+']],
        ['key' => 'consent', 'label' => 'I agree to be contacted', 'type' => 'checkbox', 'required' => true],
    ];

    $this->actingAs($user)->patch("/app/agents/{$agent->id}", [
        'lead_form_fields' => $schema,
    ])->assertRedirect();

    $stored = $agent->fresh()->lead_form_fields;
    expect($stored)->toBeArray();
    expect($stored)->toHaveCount(5);
    expect($stored[1]['key'])->toBe('email');
    expect($stored[1]['required'])->toBeTrue();
    expect($stored[3]['type'])->toBe('select');
    expect($stored[3]['options'])->toBe(['1-10', '11-50', '51-200', '201+']);
});

test('lead_form_fields=null is accepted as "use the default shape"', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent();
    $agent->forceFill([
        'lead_form_fields' => [
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
        ],
    ])->save();

    $this->actingAs($user)->patch("/app/agents/{$agent->id}", [
        'lead_form_fields' => null,
    ])->assertRedirect();

    expect($agent->fresh()->lead_form_fields)->toBeNull();
});

test('rejects more than 12 fields', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent();
    $tooMany = array_fill(0, 13, [
        'key' => 'x',
        'label' => 'X',
        'type' => 'text',
    ]);

    $this->actingAs($user)->patch("/app/agents/{$agent->id}", [
        'lead_form_fields' => $tooMany,
    ])->assertSessionHasErrors('lead_form_fields');
});

test('rejects an unknown field type', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent();

    $this->actingAs($user)->patch("/app/agents/{$agent->id}", [
        'lead_form_fields' => [
            ['key' => 'foo', 'label' => 'Foo', 'type' => 'file_upload'],
        ],
    ])->assertSessionHasErrors('lead_form_fields.0.type');
});

test('rejects an invalid key (uppercase or punctuation)', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent();

    $this->actingAs($user)->patch("/app/agents/{$agent->id}", [
        'lead_form_fields' => [
            ['key' => 'Email-Address', 'label' => 'Email', 'type' => 'email'],
        ],
    ])->assertSessionHasErrors('lead_form_fields.0.key');
});

test('rejects a missing label', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent();

    $this->actingAs($user)->patch("/app/agents/{$agent->id}", [
        'lead_form_fields' => [
            ['key' => 'foo', 'type' => 'text'],
        ],
    ])->assertSessionHasErrors('lead_form_fields.0.label');
});

test('init exposes lead_form_fields in the agent payload', function () {
    $schema = [
        ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
        ['key' => 'company', 'label' => 'Company', 'type' => 'text', 'required' => true],
    ];

    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://shop.example.com'],
        'lead_form_fields' => $schema,
    ]);

    $response = $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id]);

    $response->assertOk();
    expect($response->json('data.agent.lead_form_fields'))->toBe($schema);
});

test('init returns null lead_form_fields for a default agent', function () {
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://shop.example.com'],
    ]);

    $response = $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id]);

    $response->assertOk();
    expect($response->json('data.agent.lead_form_fields'))->toBeNull();
});

test('widget /v1/widget/leads accepts custom fields and stores them on the Lead row', function () {
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://shop.example.com'],
        'lead_form_fields' => [
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ['key' => 'company', 'label' => 'Company', 'type' => 'text', 'required' => true],
            ['key' => 'team_size', 'label' => 'Team size', 'type' => 'select', 'options' => ['1-10', '11-50']],
            ['key' => 'consent', 'label' => 'Consent', 'type' => 'checkbox', 'required' => true],
        ],
    ]);

    $init = $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk();

    $jwt = $init->json('data.jwt');

    $this->withHeaders([
        'Authorization' => "Bearer {$jwt}",
        'Origin' => 'https://shop.example.com',
    ])->postJson('/api/v1/widget/leads', [
        'email' => 'sales@example.com',
        'name' => 'Acme Co',
        'fields' => [
            'company' => 'Acme Co Ltd',
            'team_size' => '11-50',
            'consent' => true,
        ],
    ])->assertOk();

    $lead = Lead::query()->withoutGlobalScopes()
        ->where('agent_id', $agent->id)
        ->first();

    expect($lead)->not->toBeNull();
    expect($lead->email)->toBe('sales@example.com');
    expect($lead->name)->toBe('Acme Co');
    expect($lead->fields)->toMatchArray([
        'company' => 'Acme Co Ltd',
        'team_size' => '11-50',
        'consent' => true,
    ]);
});
