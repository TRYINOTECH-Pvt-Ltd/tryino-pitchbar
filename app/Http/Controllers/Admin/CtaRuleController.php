<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\CtaRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CtaRuleController
{
    public function index(Request $request, Agent $agent)
    {
        $request->user()->can('update', $agent) || abort(403);

        return inertia('app/agents/ctas', [
            'agent' => $agent->only('id', 'name'),
            'rules' => $agent->ctaRules()->orderBy('priority', 'desc')->get(),
        ]);
    }

    public function store(Request $request, Agent $agent): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'label' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'in:buy,demo,signup,book,link'],
            'conditions' => ['nullable', 'array'],
            'target' => ['nullable', 'array'],
            'enabled' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer'],
        ]);

        CtaRule::create(['agent_id' => $agent->id, ...$data]);

        return back()->with('success', 'CTA added.');
    }

    public function update(Request $request, CtaRule $ctaRule): RedirectResponse
    {
        $agent = $ctaRule->agent()->withoutWorkspaceScope()->firstOrFail();
        $request->user()->can('update', $agent) || abort(403);

        $ctaRule->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'label' => ['sometimes', 'string', 'max:255'],
            'kind' => ['sometimes', 'in:buy,demo,signup,book,link'],
            'conditions' => ['sometimes', 'nullable', 'array'],
            'target' => ['sometimes', 'nullable', 'array'],
            'enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer'],
        ]));

        return back()->with('success', 'CTA updated.');
    }

    public function destroy(Request $request, CtaRule $ctaRule): RedirectResponse
    {
        $agent = $ctaRule->agent()->withoutWorkspaceScope()->firstOrFail();
        $request->user()->can('update', $agent) || abort(403);

        $ctaRule->delete();

        // Explicit redirect to the agent's CTA page rather than `back()`
        // — Inertia DELETE responses lose the Referer header on some
        // browsers, which would leave the admin on a blank fallback URL.
        return redirect()
            ->route('agents.ctas.index', ['agent' => $agent->id])
            ->with('success', 'CTA removed.');
    }
}
