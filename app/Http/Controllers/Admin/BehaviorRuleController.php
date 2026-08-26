<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\BehaviorRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BehaviorRuleController
{
    public function index(Request $request, Agent $agent)
    {
        $request->user()->can('update', $agent) || abort(403);

        return inertia('app/agents/behavior', [
            'agent' => $agent->only('id', 'name'),
            'rules' => $agent->behaviorRules()->orderBy('priority', 'desc')->get(),
        ]);
    }

    public function store(Request $request, Agent $agent): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'in:exit_intent,idle,scroll,time,returning,utm,abandoned_cart'],
            'conditions' => ['nullable', 'array'],
            'action' => ['required', 'array'],
            'cta_rule_id' => ['nullable', 'string'],
            'enabled' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer'],
        ]);

        BehaviorRule::create(['agent_id' => $agent->id, ...$data]);

        return back()->with('success', 'Rule added.');
    }

    public function update(Request $request, BehaviorRule $behaviorRule): RedirectResponse
    {
        $agent = $behaviorRule->agent()->withoutWorkspaceScope()->firstOrFail();
        $request->user()->can('update', $agent) || abort(403);

        $behaviorRule->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'kind' => ['sometimes', 'in:exit_intent,idle,scroll,time,returning,utm,abandoned_cart'],
            'conditions' => ['sometimes', 'nullable', 'array'],
            'action' => ['sometimes', 'array'],
            'cta_rule_id' => ['sometimes', 'nullable', 'string'],
            'enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer'],
        ]));

        return back()->with('success', 'Rule updated.');
    }

    public function destroy(Request $request, BehaviorRule $behaviorRule): RedirectResponse
    {
        $agent = $behaviorRule->agent()->withoutWorkspaceScope()->firstOrFail();
        $request->user()->can('update', $agent) || abort(403);

        $behaviorRule->delete();

        return back()->with('success', 'Rule removed.');
    }
}
