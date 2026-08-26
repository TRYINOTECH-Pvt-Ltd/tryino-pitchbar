<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\BehaviorRule;
use App\Models\Chunk;
use App\Models\ContentGap;
use App\Models\Conversation;
use App\Models\CtaRule;
use App\Models\CuratedAnswer;
use App\Models\Document;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Source;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Produces a populated, realistic-looking workspace so a fresh
 * `php artisan db:seed --class=DemoSeeder` install lands on a dashboard
 * full of activity instead of an empty shell. Critical for CodeCanyon
 * reviewers who spend 60 seconds clicking around before they decide.
 *
 * Two output workspaces:
 *
 *   1. "pitchbar-demo" — owns the "Pitchbar Demo" agent that the
 *      marketing site's live widget talks to. Bootstrapped via the
 *      existing pitchbar:seed-demo-agent command.
 *
 *   2. "Demo Customer's Workspace" — the customer@mail.com workspace,
 *      populated with two agents, knowledge sources, conversations,
 *      messages, leads, content gaps, and a 30-day usage history.
 *
 * Idempotent: re-running adds more data on top, so wipe + reseed if you
 * want a clean slate (`php artisan migrate:fresh --seed`).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // 1. Bootstrap the live-demo agent for the marketing widget.
            //    Re-uses the dedicated command (idempotent).
            $this->command?->info('  Seeding live-demo agent for marketing site…');
            Artisan::call('pitchbar:seed-demo-agent');

            // 2. Populate the customer@mail.com workspace.
            $customer = User::query()->where('email', 'customer@mail.com')->first();
            if ($customer === null || $customer->default_workspace_id === null) {
                $this->command?->warn('  Skipping customer workspace seed: customer@mail.com has no workspace. Run UserSeeder first.');

                return;
            }

            $workspace = Workspace::query()->withoutGlobalScopes()
                ->where('id', $customer->default_workspace_id)
                ->first();
            if ($workspace === null) {
                $this->command?->warn('  Skipping customer workspace seed: workspace missing.');

                return;
            }

            // Upgrade the workspace to a paid plan so branding hides + quotas relax.
            $pro = Plan::query()->where('slug', 'pro')->first()
                ?? Plan::query()->where('slug', 'standard')->first();
            if ($pro !== null && $workspace->plan_id !== $pro->id) {
                $workspace->forceFill(['plan_id' => $pro->id])->save();
            }

            $this->command?->info('  Seeding agents + knowledge for '.$workspace->name.'…');
            $this->seedAgents($workspace, $customer);
        });

        $this->command?->info('  Demo seed complete.');
    }

    private function seedAgents(Workspace $workspace, User $owner): void
    {
        $marketingAgent = Agent::query()->withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('name', 'Marketing site')
            ->first();

        if ($marketingAgent === null) {
            $marketingAgent = Agent::create([
                'workspace_id' => $workspace->id,
                'name' => 'Marketing site',
                'language_default' => 'en',
                'allowed_origins' => ['https://acme.example.com'],
                'persona' => ['name' => 'Aria', 'tone' => 'warm and concise'],
                'theme' => ['primary' => '#111827', 'accent' => '#10b981', 'radius' => 12, 'position' => 'bottom-right', 'launcher_label' => 'Need help?'],
                'guardrails' => ['avoid' => ['legal advice', 'medical advice'], 'max_chars' => 2500],
                'starter_prompts' => ['How much does Pro cost?', 'Do you offer a free trial?', 'Can I talk to a human?'],
                'system_prompt' => 'You are the friendly assistant for Acme. Help visitors understand pricing, features, and next steps. Always offer to capture their email when intent is clear.',
                'confidence_threshold' => (float) config('services.rag.confidence_threshold', 0.5),
                'is_published' => true,
                'auto_index_visited_pages' => true,
            ]);
        }

        $helpAgent = Agent::query()->withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('name', 'Help center')
            ->first();

        if ($helpAgent === null) {
            $helpAgent = Agent::create([
                'workspace_id' => $workspace->id,
                'name' => 'Help center',
                'language_default' => 'en',
                'allowed_origins' => ['https://help.acme.example.com', 'https://docs.acme.example.com'],
                'persona' => ['name' => 'Riley', 'tone' => 'precise and patient'],
                'theme' => ['primary' => '#0f766e', 'accent' => '#14b8a6', 'radius' => 8, 'position' => 'bottom-right'],
                'guardrails' => ['avoid' => [], 'max_chars' => 3000],
                'starter_prompts' => ['How do I reset my password?', 'How do I export my data?', 'Where can I see my invoices?'],
                'confidence_threshold' => 0.55,
                'is_published' => true,
                'auto_index_visited_pages' => false,
            ]);
        }

        $this->seedSources($marketingAgent, $this->marketingSourceSpecs());
        $this->seedSources($helpAgent, $this->helpCenterSourceSpecs());

        $this->seedBehaviorAndCtas($marketingAgent);
        $this->seedCuratedAnswers($marketingAgent);

        $this->seedConversations($marketingAgent, count: 18, leadRate: 0.25);
        $this->seedConversations($helpAgent, count: 12, leadRate: 0.1);

        $this->seedContentGaps($marketingAgent);
        $this->seedUsage($workspace);
    }

    /**
     * @param  array<int, array{type: string, title: string, url?: string, body?: string}>  $specs
     */
    private function seedSources(Agent $agent, array $specs): void
    {
        foreach ($specs as $spec) {
            $existing = Source::query()->withoutGlobalScopes()
                ->where('agent_id', $agent->id)
                ->where('type', $spec['type'])
                ->where(function ($q) use ($spec) {
                    if (isset($spec['url'])) {
                        $q->whereJsonContains('config->url', $spec['url']);
                    } else {
                        $q->whereJsonContains('config->title', $spec['title']);
                    }
                })
                ->first();

            if ($existing !== null) {
                continue;
            }

            $source = Source::create([
                'agent_id' => $agent->id,
                'type' => $spec['type'],
                'status' => 'done',
                'last_synced_at' => now()->subDays(rand(1, 7)),
                'config' => [
                    'title' => $spec['title'],
                    'url' => $spec['url'] ?? null,
                ],
            ]);

            $document = Document::create([
                'source_id' => $source->id,
                'agent_id' => $agent->id,
                'url' => $spec['url'] ?? "demo://{$source->id}",
                'title' => $spec['title'],
                'lang' => 'en',
                'content_hash' => hash('sha256', $spec['body'] ?? $spec['title']),
                'fetched_at' => now()->subDays(rand(1, 7)),
            ]);

            // One chunk per document for the demo — enough for dashboard
            // counts and "indexed" status to look populated. Real
            // ingestion splits into many.
            Chunk::create([
                'document_id' => $document->id,
                'agent_id' => $agent->id,
                'ord' => 0,
                'text' => $spec['body'] ?? "Demo content for {$spec['title']}.",
                'token_count' => (int) (mb_strlen($spec['body'] ?? '') / 4),
            ]);
        }
    }

    private function seedBehaviorAndCtas(Agent $agent): void
    {
        $rules = [
            ['name' => 'Capture lead after 3 turns', 'kind' => 'lead_capture', 'priority' => 80, 'conditions' => ['after_messages' => 3], 'action' => ['ask_for' => ['name', 'email']]],
            ['name' => 'Notify team on low confidence', 'kind' => 'route', 'priority' => 90, 'conditions' => ['low_confidence' => true], 'action' => ['notify' => 'team@acme.example.com']],
        ];
        foreach ($rules as $r) {
            BehaviorRule::query()->withoutGlobalScopes()->updateOrCreate(
                ['agent_id' => $agent->id, 'kind' => $r['kind']],
                [
                    'name' => $r['name'],
                    'priority' => $r['priority'],
                    'conditions' => $r['conditions'],
                    'action' => $r['action'],
                    'enabled' => true,
                ],
            );
        }

        CtaRule::query()->withoutGlobalScopes()->updateOrCreate(
            ['agent_id' => $agent->id, 'name' => 'Book a demo'],
            [
                'label' => 'Book a demo',
                'kind' => 'link',
                'enabled' => true,
                'priority' => 100,
                'conditions' => ['intent' => ['pricing', 'demo']],
                'target' => [
                    'title' => 'Ready to see it live?',
                    'description' => '15-min walkthrough with a real human.',
                    'button_label' => 'Book demo',
                    'button_url' => 'https://cal.com/acme/demo',
                ],
            ],
        );
    }

    private function seedCuratedAnswers(Agent $agent): void
    {
        $entries = [
            ['question_pattern' => 'refund', 'answer' => 'We offer a 30-day money-back guarantee on all paid plans. Email billing@acme.example.com and we will process it the same business day.'],
            ['question_pattern' => 'enterprise', 'answer' => 'Enterprise plans start at $999/mo and include SSO, dedicated infrastructure, and a named CSM. Reply with your team size and we will set up a call.'],
        ];
        foreach ($entries as $e) {
            CuratedAnswer::query()->withoutGlobalScopes()->updateOrCreate(
                ['agent_id' => $agent->id, 'question_pattern' => $e['question_pattern']],
                ['answer' => $e['answer'], 'priority' => 80, 'enabled' => true, 'lang' => 'en', 'conditions' => []],
            );
        }
    }

    private function seedConversations(Agent $agent, int $count, float $leadRate): void
    {
        $alreadySeeded = Conversation::query()->withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->count() >= $count;
        if ($alreadySeeded) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $startedAt = Carbon::now()->subDays(rand(0, 28))->subHours(rand(0, 23));

            $visitor = Visitor::create([
                'agent_id' => $agent->id,
                'anonymous_id' => 'demo_'.Str::random(12),
                'ip_hash' => hash('sha256', (string) rand()),
                'ua' => 'Mozilla/5.0 (Demo)',
                'first_seen_at' => $startedAt,
                'last_seen_at' => $startedAt->copy()->addMinutes(rand(2, 25)),
                'visit_count' => rand(1, 8),
            ]);

            $conv = Conversation::create([
                'agent_id' => $agent->id,
                'visitor_id' => $visitor->id,
                'page_url' => $this->randomPageUrl($agent),
                'started_at' => $startedAt,
                'lang' => 'en',
                'is_playground' => false,
            ]);

            $turns = rand(2, 6);
            $sample = $this->sampleConversation($turns);
            foreach ($sample as $idx => $msg) {
                Message::create([
                    'conversation_id' => $conv->id,
                    'role' => $msg['role'],
                    'content' => $msg['content'],
                    'citations' => $msg['citations'] ?? [],
                    'latency_ms' => $msg['role'] === 'assistant' ? rand(280, 720) : null,
                    'created_at' => $startedAt->copy()->addSeconds($idx * rand(8, 25)),
                    'updated_at' => $startedAt->copy()->addSeconds($idx * rand(8, 25)),
                ]);
            }

            if (mt_rand(0, 99) / 100 < $leadRate) {
                Lead::query()->withoutGlobalScopes()->updateOrCreate(
                    ['agent_id' => $agent->id, 'email' => 'lead'.$i.'-'.Str::lower(Str::random(4)).'@example.com'],
                    [
                        'conversation_id' => $conv->id,
                        'name' => fake()->name(),
                        'phone' => fake()->phoneNumber(),
                        'fields' => ['company' => fake()->company()],
                        'created_at' => $startedAt->copy()->addMinutes(rand(3, 12)),
                        'updated_at' => $startedAt->copy()->addMinutes(rand(3, 12)),
                    ],
                );
            }
        }
    }

    private function seedContentGaps(Agent $agent): void
    {
        $gaps = [
            ['question' => 'Do you support SOC 2 / ISO 27001 audits?', 'occurrences' => 12],
            ['question' => 'Can I export my conversation history to BigQuery?', 'occurrences' => 7],
            ['question' => 'Is there a Zapier integration?', 'occurrences' => 5],
        ];
        foreach ($gaps as $g) {
            ContentGap::query()->withoutGlobalScopes()->updateOrCreate(
                [
                    'agent_id' => $agent->id,
                    'question_hash' => hash('sha256', $g['question']),
                ],
                [
                    'question' => $g['question'],
                    'occurrences' => $g['occurrences'],
                    'status' => 'open',
                    'last_seen_at' => now()->subHours(rand(1, 48)),
                ],
            );
        }
    }

    private function seedUsage(Workspace $workspace): void
    {
        $alreadySeeded = UsageEvent::query()->withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->count() > 50;
        if ($alreadySeeded) {
            return;
        }

        for ($d = 0; $d < 30; $d++) {
            $day = now()->subDays($d);
            $count = match (true) {
                $d < 3 => rand(15, 30),
                $d < 10 => rand(8, 20),
                default => rand(2, 12),
            };
            for ($i = 0; $i < $count; $i++) {
                UsageEvent::query()->withoutGlobalScopes()->create([
                    'workspace_id' => $workspace->id,
                    'kind' => 'conversation',
                    'quantity' => 1,
                    'occurred_at' => $day->copy()->addMinutes(rand(0, 1439)),
                ]);
            }
        }
    }

    private function randomPageUrl(Agent $agent): string
    {
        $origins = (array) ($agent->allowed_origins ?? ['https://acme.example.com']);
        $base = $origins[0] ?? 'https://acme.example.com';
        $paths = ['/', '/pricing', '/features', '/integrations', '/docs', '/blog', '/contact'];

        return rtrim($base, '/').$paths[array_rand($paths)];
    }

    /**
     * @return list<array{role: string, content: string, citations?: array}>
     */
    private function sampleConversation(int $turns): array
    {
        $bank = [
            ['user' => 'Hi! Do you have a free plan?', 'assistant' => 'Yes — the Free plan is 100 conversations a month, no card required. Upgrade to Pro at any time. Anything specific you want to compare?'],
            ['user' => 'How is your pricing?', 'assistant' => 'Standard is $49/mo for 500 conversations, Pro is $249/mo for 3,000, and Enterprise is custom. Which one fits your traffic?'],
            ['user' => 'Can I install on Shopify?', 'assistant' => 'Yes — drop a single &lt;script&gt; tag in your theme.liquid before &lt;/body&gt;. We have a Shopify-specific guide if helpful.'],
            ['user' => 'Do you handle multiple languages?', 'assistant' => 'English, Spanish, French, German, Portuguese, Japanese, Arabic, and Chinese. We auto-detect the visitor and reply in their language.'],
            ['user' => 'Where do leads go?', 'assistant' => 'Captured leads land in your inbox immediately, fire a Slack alert if connected, and can hit any outgoing webhook you configure.'],
        ];
        $picked = $bank[array_rand($bank)];

        $msgs = [
            ['role' => 'user', 'content' => $picked['user']],
            ['role' => 'assistant', 'content' => $picked['assistant'], 'citations' => [['id' => 1, 'url' => 'https://acme.example.com/pricing']]],
        ];

        if ($turns >= 4) {
            $followUps = [
                ['role' => 'user', 'content' => 'Got it — can a human take over if needed?'],
                ['role' => 'assistant', 'content' => 'Anytime. Your team sees the conversation in the inbox; one click and you are typing as the human-agent.'],
            ];
            $msgs = array_merge($msgs, $followUps);
        }

        return $msgs;
    }

    /** @return array<int, array{type: string, title: string, url?: string, body?: string}> */
    private function marketingSourceSpecs(): array
    {
        return [
            ['type' => 'url', 'title' => 'Pricing', 'url' => 'https://acme.example.com/pricing', 'body' => 'Standard $49/mo for 500 conversations. Pro $249/mo for 3,000. Enterprise custom.'],
            ['type' => 'url', 'title' => 'Features', 'url' => 'https://acme.example.com/features', 'body' => 'AI agents trained on your knowledge. 8 languages. Lead capture. Inbox + human takeover.'],
            ['type' => 'sitemap', 'title' => 'Acme blog', 'url' => 'https://acme.example.com/blog/sitemap.xml'],
            ['type' => 'text', 'title' => 'FAQ pasted from Notion', 'body' => 'Common questions about pricing, refunds, integrations. Pasted by the team for quick coverage.'],
            ['type' => 'auto', 'title' => 'Visitor-discovered: /integrations', 'url' => 'https://acme.example.com/integrations', 'body' => 'Slack, HubSpot, Salesforce, Mailchimp, Zapier.'],
        ];
    }

    /** @return array<int, array{type: string, title: string, url?: string, body?: string}> */
    private function helpCenterSourceSpecs(): array
    {
        return [
            ['type' => 'url', 'title' => 'How to reset password', 'url' => 'https://help.acme.example.com/articles/reset-password', 'body' => 'Click the lock icon in the top right, choose "Forgot password", check email, click the link, set a new one.'],
            ['type' => 'url', 'title' => 'Exporting your data', 'url' => 'https://help.acme.example.com/articles/export', 'body' => 'Settings → Privacy → Export. We email a download link within 30 minutes.'],
            ['type' => 'notion', 'title' => 'Internal: support runbook', 'body' => 'When SSL errors come in, check the OAuth token, then check the firewall ranges, then escalate.'],
        ];
    }
}
