<?php

namespace App\Support;

use App\Models\AppSetting;

class MarketingHomeContent
{
    /**
     * Resolve the landing-page payload from the singleton settings row.
     * Missing or malformed branches fall back to the shipped defaults so
     * the homepage stays renderable even while the admin edits JSON.
     *
     * @return array<string, mixed>
     */
    public static function resolve(?array $content = null): array
    {
        $payload = $content;

        if ($payload === null) {
            $payload = AppSetting::query()->find(AppSetting::SINGLETON_ID)?->marketing_home_content;
        }

        return self::mergeNodes(self::defaults(), is_array($payload) ? $payload : []);
    }

    /**
     * When the operator points the marketing site at an external docs
     * domain (header.docs_external_url, e.g. https://blengidocs.com),
     * repoint every built-in `/documentation…` link — the header
     * Documentation link and all footer doc links — at it, preserving the
     * sub-path so `/documentation/quickstart` → `{base}/quickstart`. This
     * is the one-field "use my own docs site" switch; leave it blank to
     * keep the built-in `/documentation`.
     *
     * Applied at RENDER time (controllers / MarketingShellContent), NOT
     * inside resolve() — resolve()'s output is what the admin editor
     * persists, so baking external links there would make clearing the
     * setting unable to restore the built-in `/documentation` defaults.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function applyExternalDocsUrl(array $content): array
    {
        $base = rtrim(trim((string) ($content['header']['docs_external_url'] ?? '')), '/');

        if ($base === '') {
            return $content;
        }

        // resources_href is keyed `resources_href`, not `href`, so handle
        // it explicitly; everything else (nav_items, footer links) uses an
        // `href` key picked up by the recursive walk.
        $resourcesHref = (string) ($content['header']['resources_href'] ?? '');

        if (str_starts_with($resourcesHref, '/documentation')) {
            $content['header']['resources_href'] = $base.substr($resourcesHref, strlen('/documentation'));
        }

        return self::rewriteDocHrefs($content, $base);
    }

    /**
     * Recursively rewrite any `href` value that starts with
     * `/documentation` onto the external docs base.
     */
    private static function rewriteDocHrefs(mixed $node, string $base): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if ($key === 'href' && is_string($value) && str_starts_with($value, '/documentation')) {
                $node[$key] = $base.substr($value, strlen('/documentation'));
            } else {
                $node[$key] = self::rewriteDocHrefs($value, $base);
            }
        }

        return $node;
    }

    public static function editorValue(?array $content = null): string
    {
        return json_encode(
            self::resolve($content),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) ?: '{}';
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'brand_name' => 'Pitchbar',
            'nav_items' => [
                // "Home" not "Product": the logo + this link both go to /,
                // so labelling it "Product" while pointing at the homepage
                // confused operators (buyer report). No standalone product
                // page exists, so the honest label is Home.
                ['label' => 'Home', 'href' => '/'],
                ['label' => 'Pricing', 'href' => '/pricing'],
                ['label' => 'How it works', 'href' => '/how-it-works'],
                ['label' => 'Integrations', 'href' => '/integrations'],
            ],
            'header' => [
                'resources_label' => 'Documentation',
                'resources_href' => '/documentation',
                // Point the marketing site's doc links at an external docs
                // domain (e.g. https://yourdocs.com). Blank = use the
                // built-in /documentation. Applied by applyExternalDocsUrl().
                'docs_external_url' => '',
                // Show the Documentation link in the public nav. Off by
                // default (self-hosted buyers usually don't want it); demo
                // installs always show it regardless of this flag.
                'show_documentation' => false,
                'primary_button_label' => 'Book demo',
                'primary_button_href' => '__primary__',
            ],
            'hero' => [
                'badge' => 'AI sales assistant for high-intent pages',
                'line_one' => 'Turn every',
                'accent' => 'high-intent',
                'line_two_suffix' => 'page',
                'line_three_prefix' => 'into a',
                'line_three_highlight' => 'sales',
                'line_four_highlight' => 'conversation.',
                'description' => 'Pitchbar answers questions, qualifies visitors, and guides them to the next step, so your team closes more, faster.',
                'site_test_label' => 'Test it on your site',
                'site_test_placeholder' => 'https://yourwebsite.com/pricing',
                'site_test_button_label' => 'Try it',
                'site_test_helper' => 'No credit card required. Instant preview.',
                'live_demo_notice' => 'Live demo agent is active on this page.',
            ],
            'chat_preview' => [
                'title' => 'Pitchbar AI',
                'badge' => 'Live',
                'question' => 'How does your pricing work for teams of 20?',
                'answer' => 'For teams of 20, the Pro plan is the best fit. It includes advanced analytics, priority support, and unlimited pages.',
                'plan_badge' => 'RECOMMENDED',
                'plan_name' => 'Pro',
                'plan_price' => '$199',
                'plan_interval' => '/month',
                'plan_note' => 'Billed monthly for up to 20 users',
                'plan_features' => [
                    'Advanced analytics',
                    'Priority support',
                    'Unlimited pages',
                ],
                'plan_button_label' => 'Start Pro plan',
                'typing_label' => 'AI is typing...',
                'powered_by_prefix' => 'Powered by',
            ],
            'stats' => [
                [
                    'icon' => 'BarChart3',
                    'value' => '53%',
                    'label' => 'Lift in conversions on high-intent pages',
                ],
                [
                    'icon' => 'Zap',
                    'value' => '<1s',
                    'label' => 'Average response time',
                ],
                [
                    'icon' => 'Clock3',
                    'value' => '5 min',
                    'label' => 'Setup time to go live',
                ],
                [
                    'icon' => 'Target',
                    'value' => '24/7',
                    'label' => 'Always-on conversations that never sleep',
                ],
            ],
            'video' => [
                'badge' => 'Video walkthrough',
                'title' => 'Watch Pitchbar handle a real buyer question.',
                'description' => 'This short walkthrough shows how the assistant appears on a high-intent page, answers with the right context, and guides the visitor to the best next step.',
                'bullets' => [
                    'Trigger the assistant on pricing and product pages',
                    'Answer product questions with relevant context',
                    'Route qualified visitors to the right CTA',
                ],
                'duration_label' => '2 min walkthrough',
                'tag_label' => 'Pricing page demo',
                'scene_label' => 'Scene 01',
                'card_title' => 'Pricing page questions, answered in context.',
                'timecode' => '02:18',
                'chips' => [
                    'Greeting trigger',
                    'Plan recommendation',
                    'CTA handoff',
                ],
                'footer_title' => 'See how the assistant greets, answers, qualifies, and routes in one flow.',
                'footer_description' => 'Open the walkthrough for the full product story before you scroll into the rest of the page.',
                'button_label' => 'Watch video',
                // Vimeo / YouTube URL or any other embed; rendered inline in
                // a modal lightbox by the home page video card.
                'href' => 'https://vimeo.com/1190236104',
            ],
            'where_it_fits' => [
                'badge' => 'Where it fits',
                'title' => 'Built for the pages that drive the right conversations.',
                'cards' => [
                    [
                        'icon' => 'Tags',
                        'title' => 'Pricing pages',
                        'description' => 'Answer pricing questions, compare plans, and convert more visitors.',
                    ],
                    [
                        'icon' => 'Box',
                        'title' => 'Product pages',
                        'description' => 'Explain features, highlight benefits, and move buyers forward.',
                    ],
                    [
                        'icon' => 'BookOpen',
                        'title' => 'Docs & help pages',
                        'description' => 'Resolve questions, point to answers, and reduce support load.',
                    ],
                ],
            ],
            'feature_grid' => [
                'badge' => 'Powerful under the hood',
                'title' => 'Simple for visitors. Fully controlled by your team.',
                'cards' => [
                    [
                        'icon' => 'Tags',
                        'title' => 'Behavior triggers',
                        'description' => 'Awake the right message at the right moment based on visitor intent and page context.',
                    ],
                    [
                        'icon' => 'Zap',
                        'title' => 'Streaming answers',
                        'description' => 'Real-time, cited answers sourced from your content for instant clarity.',
                    ],
                    [
                        'icon' => 'Target',
                        'title' => 'Smart CTAs',
                        'description' => 'AI recommends the next best step and routes visitors to the right action.',
                    ],
                    [
                        'icon' => 'CircleCheck',
                        'title' => 'Auto-trains on your content',
                        'description' => 'Continuously learns from your docs, pages, and updates with no manual retraining.',
                    ],
                    [
                        'icon' => 'ClipboardList',
                        'title' => 'Lead capture & routing',
                        'description' => 'Qualify leads, capture details, and route to the right person or system.',
                    ],
                    [
                        'icon' => 'TrendingUp',
                        'title' => 'Self-improving insights',
                        'description' => 'Surface what visitors ask, where they drop off, and how to improve conversions.',
                    ],
                ],
            ],
            'control' => [
                'badge' => 'You are in control',
                'title' => 'Tune the AI to match your messaging and goals.',
                'description' => 'Pitchbar adapts to your voice, your offer, and your go-to-market motion, so every conversation feels on-brand and on-strategy.',
                'callouts' => [
                    [
                        'icon' => 'ShieldCheck',
                        'title' => 'Enterprise ready',
                        'description' => 'SSO, SOC 2, GDPR compliant, and built with security in mind.',
                    ],
                    [
                        'icon' => 'LockKeyhole',
                        'title' => 'Your data stays yours',
                        'description' => 'We use your content to answer, never to train public models.',
                    ],
                ],
                'settings_card_title' => 'Conversation settings',
                'settings_rows' => [
                    [
                        'label' => 'Trigger rule',
                        'hint' => 'When should Pitchbar appear?',
                        'value' => 'High intent - Pricing page',
                    ],
                    [
                        'label' => 'Answer style',
                        'hint' => 'How should Pitchbar respond?',
                        'value' => 'Helpful, concise, and solution-oriented',
                    ],
                    [
                        'label' => 'CTA routing',
                        'hint' => 'Where should visitors go next?',
                        'value' => 'Route to Plan selection page',
                    ],
                    [
                        'label' => 'Follow-up signal',
                        'hint' => 'When should Pitchbar re-engage?',
                        'value' => 'After 30s of inactivity',
                    ],
                ],
                'cancel_label' => 'Cancel',
                'save_button_label' => 'Save changes',
            ],
            'steps' => [
                'badge' => 'From paste to live',
                'title' => 'From paste to live in 3 simple steps.',
                'items' => [
                    [
                        'icon' => 'ClipboardList',
                        'title' => 'Paste your content',
                        'description' => 'Add URLs, docs, or copy. Pitchbar learns your content automatically.',
                    ],
                    [
                        'icon' => 'Sparkles',
                        'title' => 'Configure & customize',
                        'description' => 'Set triggers, tone, CTAs, and routing in minutes.',
                    ],
                    [
                        'icon' => 'CircleCheck',
                        'title' => 'Go live & optimize',
                        'description' => 'Embed with one line of code and start improving conversations.',
                    ],
                ],
            ],
            'insights' => [
                'badge' => 'Insights that drive growth',
                'chart_title' => 'Every conversation becomes a signal.',
                'chart_description' => 'See what visitors ask, what moves them forward, and where you can improve.',
                'metric_label' => 'Conversations',
                'metric_value' => '12,842',
                'metric_trend' => '+28% vs last 30 days',
                'chart_points' => [45, 53, 62, 80, 77, 91, 87, 103, 119, 111, 127, 141, 135, 160, 154, 178],
                'chart_labels' => ['Apr 19', 'Apr 26', 'May 3', 'May 10', 'May 17'],
                'cards' => [
                    [
                        'icon' => 'Sparkles',
                        'title' => 'Curated answers',
                        'description' => 'Top visitor questions and your best performing answers.',
                    ],
                    [
                        'icon' => 'ClipboardList',
                        'title' => 'Experiments',
                        'description' => 'Test messages and CTAs to see what drives more conversions.',
                    ],
                    [
                        'icon' => 'ShieldCheck',
                        'title' => 'Lead context',
                        'description' => 'See where leads came from and what they were interested in.',
                    ],
                ],
            ],
            'testimonials' => [
                'badge' => 'What teams are saying',
                'title' => 'Trusted by teams who care about every visitor',
                'kicker' => 'From founders, growth leads, and customer-experience teams running Pitchbar on their busiest pages.',
                'items' => [
                    [
                        'name' => 'Maya R.',
                        'role' => 'Head of Growth',
                        'company' => 'Northpath SaaS',
                        'quote' => 'We replaced a static FAQ widget with Pitchbar and lifted demo bookings 38% in the first month. The keyword-triggered handoff to our SDR Slack is the part our team loves the most.',
                    ],
                    [
                        'name' => 'Daniel K.',
                        'role' => 'Founder',
                        'company' => 'Hopper Print Co.',
                        'quote' => 'Buyers ask for shipping ETAs in a dozen ways. Pitchbar answers from our actual store policies, not a hallucinated guess. Refund requests are down because the bot answers them correctly the first time.',
                    ],
                    [
                        'name' => 'Priya S.',
                        'role' => 'Customer Experience Lead',
                        'company' => 'Loom Logistics',
                        'quote' => 'The pre-chat lead gate is what closed the deal for us — every conversation comes with name + email up front, so the inbox is qualified before a human touches it.',
                    ],
                    [
                        'name' => 'Alex T.',
                        'role' => 'CTO',
                        'company' => 'Ledgerstack',
                        'quote' => 'I evaluated five chat tools. Pitchbar was the only one we could self-host on our own Cloudflare account in under an hour, with the data never leaving our infra. The widget is also tiny — 18 KB gzipped, you can feel it.',
                    ],
                    [
                        'name' => 'Sara N.',
                        'role' => 'Marketing Manager',
                        'company' => 'Crestform Studio',
                        'quote' => 'The visual workflow editor felt familiar from day one — anyone who has used a chatbot builder before can pick it up. The branching means our refund flow actually qualifies the right leads, not just everyone with the word "refund".',
                    ],
                ],
            ],
            'faq' => [
                'badge' => 'Frequently asked',
                'title' => 'Everything you need to know',
                'kicker' => 'Have a question we missed? Open the assistant on this page and ask it — that\'s the bot answering from our own docs.',
                'items' => [
                    [
                        'question' => 'How does Pitchbar know what to say to my visitors?',
                        'answer' => 'It reads your website, docs, and any extra knowledge sources you upload. Every visitor turn runs retrieval-augmented generation — the bot grounds every reply in chunks pulled from your real content, with citations the visitor can click. There is no LLM hallucination on facts you have not provided.',
                    ],
                    [
                        'question' => 'Will it slow down my page?',
                        'answer' => 'The widget bundle is roughly 18 KB gzipped and lazy-loads on first visitor interaction. Your page paint is unaffected. The chat itself streams the first token within a second; the latency budget is enforced by the engineering team and regression-tested.',
                    ],
                    [
                        'question' => 'Can I self-host on my own infrastructure?',
                        'answer' => 'Yes. Pitchbar runs on Laravel + MySQL/Postgres + Redis. We support Cloudflare Workers AI for the LLM and embeddings, OpenAI / OpenRouter as alternatives, and Cloudflare Vectorize or Qdrant for the vector store. The Extended License lets you run the platform as a paid SaaS for your own customers.',
                    ],
                    [
                        'question' => 'How does multi-tenancy work?',
                        'answer' => 'Every workspace is fully isolated. Models with a workspace scope (agents, conversations, leads, sources) enforce a global query scope keyed off the authenticated user OR the widget JWT — there is no path where workspace A can read workspace B\'s data, and the multi-tenancy regression test is part of CI.',
                    ],
                    [
                        'question' => 'Can I customise the widget look?',
                        'answer' => 'The Customize page on every agent lets you set the primary + accent colours, corner radius, position (centered bar, bottom-right bubble, bottom-left), launcher label, persona name, and starter prompts. The pre-chat lead gate, the lead form fields, and the per-page restricted-paths list are all per-agent toggles too.',
                    ],
                    [
                        'question' => 'How are leads captured?',
                        'answer' => 'Two ways. Either the LLM raises a lead-capture signal mid-conversation (e.g. when the visitor expresses buying intent), in which case an inline form drops into the chat thread; or you turn on the pre-chat name+email gate and the visitor identifies themselves before chatting. Each agent can also have a custom lead-form schema with text/email/tel/textarea/select/checkbox fields.',
                    ],
                    [
                        'question' => 'Does it handle multiple languages?',
                        'answer' => 'Yes. Each agent picks a default language; visitors are auto-detected from their Accept-Language header. The supported set in the Customize panel is en, es, fr, de, pt, ja, ar, zh — adding more is a single-line edit in the form-request validator.',
                    ],
                    [
                        'question' => 'Can the bot hand off to a human?',
                        'answer' => 'Yes. Workflows can be set up to detect handoff intent (a keyword, an explicit "talk to a human" message, or a sentiment-routing rule) and surface a Live Agent panel inside the widget. Workspace members on the dashboard see the conversation in real time and can take over.',
                    ],
                    [
                        'question' => 'What about GDPR / data privacy?',
                        'answer' => 'Visitor email + name only enter the database when they fill in a lead form. The chat transcript itself is workspace-scoped and deletable. Buyers running Pitchbar in regulated industries get the full benefit of self-hosting: the data never leaves your AWS / GCP / Hetzner / bare-metal box.',
                    ],
                    [
                        'question' => 'Do I need a credit card to try it?',
                        'answer' => 'No. The free tier lets you create one agent, ingest a few sources, and have real conversations. Paid plans unlock additional agents, higher conversation caps, and white-label removal of the Powered by footer.',
                    ],
                ],
            ],
            'final_cta' => [
                'title' => 'Ready to turn more traffic into pipeline?',
                'description' => 'Join leading teams who use Pitchbar to have more conversations, close more deals, and grow faster.',
                'primary_button_label' => 'Book a demo',
                'primary_button_href' => '__primary__',
                'secondary_button_label' => 'Try live demo',
                'secondary_button_href' => '__primary__',
            ],
            'footer' => [
                'brand_description' => 'AI sales assistant for high-intent pages that drives real results.',
                'socials' => [
                    ['label' => 'in', 'href' => '#'],
                    ['label' => 'X', 'href' => '#'],
                    ['label' => 'yt', 'href' => '#'],
                ],
                'groups' => [
                    [
                        'title' => 'Product',
                        'links' => [
                            ['label' => 'How it works', 'href' => '/how-it-works'],
                            ['label' => 'Pricing', 'href' => '/pricing'],
                            ['label' => 'Integrations', 'href' => '/integrations'],
                            ['label' => 'Widget API', 'href' => '/documentation/widget-api'],
                        ],
                    ],
                    [
                        'title' => 'Build',
                        'links' => [
                            ['label' => 'Quickstart', 'href' => '/documentation/quickstart'],
                            ['label' => 'Embed the widget', 'href' => '/documentation/embed'],
                            ['label' => 'Knowledge sources', 'href' => '/documentation/knowledge'],
                            ['label' => 'Behavior rules', 'href' => '/documentation/behavior-rules'],
                        ],
                    ],
                    [
                        'title' => 'Resources',
                        'links' => [
                            ['label' => 'Documentation', 'href' => '/documentation'],
                            ['label' => 'Architecture', 'href' => '/documentation/architecture'],
                            ['label' => 'Allowed origins', 'href' => '/documentation/allowed-origins'],
                            ['label' => 'Outgoing webhooks', 'href' => '/documentation/webhooks'],
                        ],
                    ],
                ],
                'legal_title' => 'Legal',
                'legal_links' => [
                    ['label' => 'Privacy', 'href' => '/privacy'],
                    ['label' => 'Terms', 'href' => '/terms'],
                    ['label' => 'Security', 'href' => '/privacy'],
                    ['label' => 'Trust center', 'href' => '/terms'],
                ],
                'copyright' => 'Copyright 2026 Pitchbar Inc. All rights reserved.',
            ],
        ];
    }

    private static function mergeNodes(mixed $defaults, mixed $overrides): mixed
    {
        if (is_array($defaults)) {
            if (! is_array($overrides)) {
                return $defaults;
            }

            if (array_is_list($defaults)) {
                $template = $defaults[0] ?? null;
                $resolved = [];

                foreach ($overrides as $index => $overrideNode) {
                    $defaultNode = $defaults[$index] ?? $template;

                    if ($defaultNode === null) {
                        $resolved[] = $overrideNode;

                        continue;
                    }

                    $resolved[] = self::mergeNodes($defaultNode, $overrideNode);
                }

                return $resolved;
            }

            $resolved = [];

            foreach ($defaults as $key => $value) {
                $resolved[$key] = self::mergeNodes($value, $overrides[$key] ?? null);
            }

            return $resolved;
        }

        if (is_string($defaults)) {
            return is_scalar($overrides) ? (string) $overrides : $defaults;
        }

        if (is_int($defaults)) {
            return is_numeric($overrides) ? (int) $overrides : $defaults;
        }

        if (is_float($defaults)) {
            return is_numeric($overrides) ? (float) $overrides : $defaults;
        }

        if (is_bool($defaults)) {
            return is_bool($overrides) ? $overrides : $defaults;
        }

        return $overrides ?? $defaults;
    }
}
