<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\Experiment;
use App\Models\Variant;
use App\Services\Experiments\ExperimentResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExperimentController
{
    public function index(Request $request, Agent $agent): Response
    {
        $request->user()->can('update', $agent) || abort(403);

        $experiments = $agent->experiments()
            ->with('variants:id,experiment_id,name,weight,config')
            ->latest()
            ->get();

        return Inertia::render('app/agents/experiments', [
            'agent' => $agent->only('id', 'name'),
            'experiments' => $experiments->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'kind' => $e->kind,
                'status' => $e->status,
                'started_at' => $e->started_at?->toIso8601String(),
                'stopped_at' => $e->stopped_at?->toIso8601String(),
                'variants' => $e->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'weight' => $v->weight,
                    'config' => (array) ($v->config ?? []),
                ]),
            ]),
        ]);
    }

    public function store(Request $request, Agent $agent): RedirectResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'in:persona,cta,trigger'],
            'variants' => ['required', 'array', 'min:2', 'max:5'],
            'variants.*.name' => ['required', 'string', 'max:255'],
            'variants.*.weight' => ['required', 'integer', 'min:1', 'max:100'],
            // Variant `config` is the override that the resolver shallow-merges
            // into the runtime agent. Today only `persona` kind reads
            // `config.persona.{name,tone}`. CTA / trigger configs are
            // measurement-only — saved for future runtime hooks.
            'variants.*.config' => ['sometimes', 'array', 'max:8'],
            'variants.*.config.persona.name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'variants.*.config.persona.tone' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        // Variant names must be unique within an experiment — reports
        // group by name, so duplicates would collide and make the
        // comparison meaningless. For persona kind, additionally
        // dedupe by the resolved persona.name (which auto-fills from
        // variant name when blank) so "Aria + Aria" can't sneak
        // through under different variant labels.
        $names = array_map(static fn ($v) => strtolower(trim((string) ($v['name'] ?? ''))), $data['variants']);
        if (count($names) !== count(array_unique($names))) {
            throw ValidationException::withMessages([
                'variants' => 'Variant names must be unique within an experiment.',
            ]);
        }

        if ($data['kind'] === 'persona') {
            $personaNames = array_map(static function ($v) {
                $resolved = $v['config']['persona']['name'] ?? null;
                if (! is_string($resolved) || $resolved === '') {
                    $resolved = (string) ($v['name'] ?? '');
                }

                return strtolower(trim($resolved));
            }, $data['variants']);
            if (count($personaNames) !== count(array_unique($personaNames))) {
                throw ValidationException::withMessages([
                    'variants' => 'Persona names must be unique within an experiment so reports can tell variants apart.',
                ]);
            }
        }

        $experiment = Experiment::create([
            'agent_id' => $agent->id,
            'name' => $data['name'],
            'kind' => $data['kind'],
            'status' => 'draft',
        ]);

        foreach ($data['variants'] as $variant) {
            $config = (array) ($variant['config'] ?? []);
            // Auto-fill persona.name from the variant name when the
            // operator gave us a variant name but no explicit
            // persona.name — saves a click and matches the doc example
            // `{ "persona": { "name": "Aria", "tone": "..." } }` where
            // name often equals the variant label.
            if ($data['kind'] === 'persona') {
                $personaName = $config['persona']['name'] ?? null;
                if ($personaName === null || $personaName === '') {
                    $config['persona']['name'] = $variant['name'];
                }
            }

            Variant::create([
                'experiment_id' => $experiment->id,
                'name' => $variant['name'],
                'weight' => $variant['weight'],
                'config' => $config,
            ]);
        }

        return back()->with('success', 'Experiment created.');
    }

    public function start(Request $request, Experiment $experiment): RedirectResponse
    {
        $agent = $experiment->agent()->withoutWorkspaceScope()->firstOrFail();
        $request->user()->can('update', $agent) || abort(403);

        $experiment->forceFill(['status' => 'running', 'started_at' => now(), 'stopped_at' => null])->save();
        ExperimentResolver::forget($agent->id);

        return back()->with('success', 'Experiment running.');
    }

    public function stop(Request $request, Experiment $experiment): RedirectResponse
    {
        $agent = $experiment->agent()->withoutWorkspaceScope()->firstOrFail();
        $request->user()->can('update', $agent) || abort(403);

        $experiment->forceFill(['status' => 'stopped', 'stopped_at' => now()])->save();
        ExperimentResolver::forget($agent->id);

        return back()->with('success', 'Experiment stopped.');
    }

    public function destroy(Request $request, Experiment $experiment): RedirectResponse
    {
        $agent = $experiment->agent()->withoutWorkspaceScope()->firstOrFail();
        $request->user()->can('update', $agent) || abort(403);

        $experiment->delete();
        ExperimentResolver::forget($agent->id);

        return back()->with('success', 'Experiment deleted.');
    }
}
