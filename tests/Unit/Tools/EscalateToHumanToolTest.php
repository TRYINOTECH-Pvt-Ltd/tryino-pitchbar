<?php

use App\Models\Agent;
use App\Services\Tools\Tools\EscalateToHumanTool;

test('escalate_to_human returns a result + an escalation_button block', function () {
    $tool = new EscalateToHumanTool;
    $agent = new Agent;

    $out = $tool->execute(['reason' => 'visitor frustrated'], $agent);

    expect($out)->toHaveKeys(['result', 'block']);
    expect($out['block']['type'])->toBe('escalation_button');
    expect($out['block']['payload']['reason'])->toBe('visitor frustrated');
    expect($out['block']['payload']['label'])->toBeString();
});

test('reason defaults when missing', function () {
    $tool = new EscalateToHumanTool;
    $out = $tool->execute([], new Agent);

    expect($out['block']['payload']['reason'])->toBeString()->not->toBe('');
});

test('capability slug matches help_center preset capability', function () {
    expect((new EscalateToHumanTool)->capability())->toBe('ticket_escalation');
});

test('description tightly scopes invocation to explicit human asks', function () {
    $desc = (new EscalateToHumanTool)->description();
    // The buyer-fix anchor: description should tell the LLM to call
    // ONLY when the visitor explicitly asks. Without this anchor small
    // Workers AI models called the tool on every reply.
    expect($desc)->toContain('ONLY');
    expect($desc)->toContain('explicit');
    expect(strtolower($desc))->toContain('do not call');
});
