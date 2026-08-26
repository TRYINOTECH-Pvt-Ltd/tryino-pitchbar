<?php

namespace App\Support;

/**
 * Sidebar navigation tree for the public /documentation site. The
 * controller iterates this list to render the left nav and to resolve a
 * slug → page-title + Blade partial. Adding a page is two steps: drop a
 * Blade partial under `resources/views/documentation/pages/{slug}.blade.php`
 * and add an entry here.
 */
final class DocumentationNav
{
    /**
     * @return list<array{title: string, items: list<array{slug: string, title: string}>}>
     */
    public static function tree(): array
    {
        return [
            [
                'title' => 'Get started',
                'items' => [
                    ['slug' => 'welcome', 'title' => 'Welcome'],
                    ['slug' => 'installation', 'title' => 'Install Pitchbar (self-host)'],
                    ['slug' => 'quickstart', 'title' => 'Quickstart'],
                    ['slug' => 'concepts', 'title' => 'Core concepts'],
                ],
            ],
            [
                'title' => 'Improve your agent',
                'items' => [
                    ['slug' => 'content-gaps', 'title' => 'Content gaps (self-improvement)'],
                ],
            ],
            [
                'title' => 'Build your agent',
                'items' => [
                    ['slug' => 'agents', 'title' => 'Agents'],
                    ['slug' => 'site-types', 'title' => 'Site types & vertical presets'],
                    ['slug' => 'tool-calling', 'title' => 'Tools & rich messages'],
                    ['slug' => 'knowledge', 'title' => 'Knowledge sources'],
                    ['slug' => 'auto-index', 'title' => 'Auto-index visited pages'],
                    ['slug' => 'customize', 'title' => 'Persona, theme & prompts'],
                    ['slug' => 'behavior-rules', 'title' => 'Behavior rules & triggers'],
                    ['slug' => 'curated-answers', 'title' => 'Curated answers & CTAs'],
                    ['slug' => 'workflows', 'title' => 'Workflows'],
                    ['slug' => 'experiments', 'title' => 'A/B experiments'],
                    ['slug' => 'playground', 'title' => 'Playground'],
                ],
            ],
            [
                'title' => 'Embed the widget',
                'items' => [
                    ['slug' => 'embed', 'title' => 'Install snippet'],
                    ['slug' => 'allowed-origins', 'title' => 'Allowed origins'],
                    ['slug' => 'widget-versioning', 'title' => 'Versioning & cache busting'],
                    ['slug' => 'widget-features', 'title' => 'Voice, leads & persistence'],
                ],
            ],
            [
                'title' => 'Run your workspace',
                'items' => [
                    ['slug' => 'inbox', 'title' => 'Inbox & human takeover'],
                    ['slug' => 'lead-scoring', 'title' => 'Lead scoring & trajectory'],
                    ['slug' => 'system-health', 'title' => 'System health & diagnostics'],
                    ['slug' => 'marketing-widget', 'title' => 'Widget on the marketing site'],
                    ['slug' => 'marketing-themes', 'title' => 'Pluggable marketing themes'],
                    ['slug' => 'live-chat', 'title' => 'Live human handoff'],
                    ['slug' => 'tickets', 'title' => 'Tickets'],
                    ['slug' => 'analytics', 'title' => 'Analytics & gaps'],
                    ['slug' => 'csat-trend', 'title' => 'Visitor satisfaction (CSAT)'],
                    ['slug' => 'billing', 'title' => 'Billing & plans'],
                    ['slug' => 'byok', 'title' => 'BYOK (workspace AI keys)'],
                    ['slug' => 'model-picker', 'title' => 'Picking a fast model'],
                    ['slug' => 'members', 'title' => 'Members & roles'],
                    ['slug' => 'integrations', 'title' => 'Integrations'],
                    ['slug' => 'internationalization', 'title' => 'Languages & i18n'],
                    ['slug' => 'gdpr-dsr', 'title' => 'GDPR data subject requests'],
                    ['slug' => 'audit-log', 'title' => 'Audit log'],
                    ['slug' => 'conversation-export', 'title' => 'Conversation export'],
                ],
            ],
            [
                'title' => 'WordPress & WooCommerce',
                'items' => [
                    ['slug' => 'wordpress-integration', 'title' => 'Overview'],
                    ['slug' => 'wordpress-install', 'title' => 'Install & connect'],
                    ['slug' => 'wordpress-plugin-distribution', 'title' => 'Plugin distribution'],
                    ['slug' => 'wordpress-sync', 'title' => 'Content sync'],
                    ['slug' => 'wordpress-page-builders', 'title' => 'Page builders'],
                    ['slug' => 'wordpress-woocommerce', 'title' => 'WooCommerce deep links'],
                    ['slug' => 'wordpress-rest-api', 'title' => 'REST API reference'],
                    ['slug' => 'wordpress-troubleshooting', 'title' => 'Troubleshooting'],
                ],
            ],
            [
                'title' => 'External integrations (MCP)',
                'items' => [
                    ['slug' => 'mcp', 'title' => 'MCP overview'],
                    ['slug' => 'mcp-setup', 'title' => 'Attach an MCP server'],
                    ['slug' => 'mcp-tool-whitelisting', 'title' => 'Tool whitelist & destructive actions'],
                    ['slug' => 'mcp-security', 'title' => 'Security model'],
                    ['slug' => 'mcp-troubleshooting', 'title' => 'Troubleshooting'],
                ],
            ],
            [
                'title' => 'Platform admin',
                'items' => [
                    ['slug' => 'admin-overview', 'title' => 'Platform overview'],
                    ['slug' => 'admin-users', 'title' => 'Users (incl. delete)'],
                    ['slug' => 'admin-plans', 'title' => 'Plans & Stripe sync'],
                    ['slug' => 'admin-translations', 'title' => 'Translation manager (edit any language)'],
                    ['slug' => 'admin-integration-cards', 'title' => 'Integration card copy'],
                    ['slug' => 'admin-pwa', 'title' => 'PWA install & icons'],
                    ['slug' => 'admin-health', 'title' => 'Site health & failed jobs'],
                    ['slug' => 'admin-board', 'title' => 'Internal Kanban board'],
                    ['slug' => 'changelog', 'title' => 'Application changelog'],
                ],
            ],
            [
                'title' => 'Troubleshooting',
                'items' => [
                    ['slug' => 'troubleshooting-widget', 'title' => "Widget doesn't show"],
                    ['slug' => 'widget-monitor', 'title' => 'Widget Monitor: errors & provider failover'],
                    ['slug' => 'hotpath-latency', 'title' => 'Slow replies: find the bottleneck'],
                    ['slug' => 'turn-debugger', 'title' => 'Turn debugger: per-conversation forensics'],
                    ['slug' => 'troubleshooting-cloudflare-401', 'title' => 'Cloudflare 401'],
                    ['slug' => 'troubleshooting-vector-dim', 'title' => 'Vector dim mismatch'],
                    ['slug' => 'vector-failover', 'title' => 'Vector circuit breaker'],
                ],
            ],
            [
                'title' => 'API reference',
                'items' => [
                    ['slug' => 'widget-api', 'title' => 'Widget API'],
                    ['slug' => 'api-ingest', 'title' => 'Sources ingest API'],
                    ['slug' => 'rate-limits', 'title' => 'Rate limits & headers'],
                    ['slug' => 'webhooks', 'title' => 'Outgoing webhooks'],
                    ['slug' => 'cta-context', 'title' => 'Signed CTA context'],
                ],
            ],
            [
                'title' => 'Architecture',
                'items' => [
                    ['slug' => 'architecture', 'title' => 'Stack & layout'],
                    ['slug' => 'data-model', 'title' => 'Data model'],
                    ['slug' => 'rag-pipeline', 'title' => 'RAG pipeline'],
                    ['slug' => 'crawler-chain', 'title' => 'Crawler chain & vision OCR'],
                    ['slug' => 'hot-path', 'title' => 'Hot path & latency'],
                    ['slug' => 'multi-tenancy', 'title' => 'Multi-tenancy'],
                    ['slug' => 'security', 'title' => 'Security model'],
                    ['slug' => 'key-rotation', 'title' => 'APP_KEY rotation'],
                ],
            ],
            [
                'title' => 'Operate',
                'items' => [
                    ['slug' => 'env', 'title' => 'Environment variables'],
                    ['slug' => 'commands', 'title' => 'Artisan commands'],
                    ['slug' => 'deployment', 'title' => 'Deployment'],
                    ['slug' => 'ssr-setup', 'title' => 'Inertia SSR & PM2 setup'],
                    ['slug' => 'subfolder-install', 'title' => 'Subfolder install'],
                    ['slug' => 'observability', 'title' => 'Observability'],
                    ['slug' => 'whitelabel-audit', 'title' => 'White-label audit'],
                    ['slug' => 'seo', 'title' => 'SEO surface'],
                    ['slug' => 'cloudflare-costs', 'title' => 'Cloudflare API costs & usage'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{title: string, group: string}>
     */
    public static function flat(): array
    {
        $out = [];
        foreach (self::tree() as $group) {
            foreach ($group['items'] as $item) {
                $out[$item['slug']] = [
                    'title' => $item['title'],
                    'group' => $group['title'],
                ];
            }
        }

        return $out;
    }

    /**
     * @return array{prev: ?array{slug: string, title: string}, next: ?array{slug: string, title: string}}
     */
    public static function neighbors(string $slug): array
    {
        $linear = [];
        foreach (self::tree() as $group) {
            foreach ($group['items'] as $item) {
                $linear[] = $item;
            }
        }

        $idx = null;
        foreach ($linear as $i => $item) {
            if ($item['slug'] === $slug) {
                $idx = $i;
                break;
            }
        }

        if ($idx === null) {
            return ['prev' => null, 'next' => null];
        }

        return [
            'prev' => $idx > 0 ? $linear[$idx - 1] : null,
            'next' => $idx < count($linear) - 1 ? $linear[$idx + 1] : null,
        ];
    }
}
