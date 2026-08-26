<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\IntegrationConnection;
use App\Models\Lead;
use App\Models\Source;
use App\Models\WebhookSubscription;
use App\Services\Billing\PlanLimits;
use App\Services\Integrations\SlackPusher;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace-level integrations: Slack (incoming webhook), with stubs ready
 * for HubSpot/Pipedrive once their OAuth flows are scoped.
 *
 * Slack today is the simplest pattern that actually works: the user pastes
 * an Incoming Webhook URL, we encrypt-at-rest in `credentials_encrypted`,
 * and RouteLeadJob calls SlackPusher on every lead capture.
 */
class IntegrationController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function index(Request $request): Response
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        // WordPress-plugin connections: every agent whose
        // `wp_integration` column has been stamped at least once by a
        // sync / delta call from the WP plugin. Surfaced as cards so
        // customers can see which agents have a live plugin pointed at
        // them without leaving the integrations page.
        $wordpressConnections = Agent::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('wp_integration')
            ->orderBy('name')
            ->get(['id', 'name', 'wp_integration'])
            ->map(function (Agent $agent) {
                $meta = is_array($agent->wp_integration) ? $agent->wp_integration : [];

                return [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'site_url' => is_string($meta['site_url'] ?? null) ? $meta['site_url'] : null,
                    'plugin_version' => is_string($meta['plugin_version'] ?? null) ? $meta['plugin_version'] : null,
                    'wordpress_version' => is_string($meta['wordpress_version'] ?? null) ? $meta['wordpress_version'] : null,
                    'woocommerce_active' => is_bool($meta['woocommerce_active'] ?? null) ? $meta['woocommerce_active'] : null,
                    'last_seen_at' => is_string($meta['last_seen_at'] ?? null) ? $meta['last_seen_at'] : null,
                ];
            })
            ->values();

        $agentIds = Agent::query()->where('workspace_id', $workspace->id)->pluck('id');

        $sourceCountsByType = Source::query()->withoutWorkspaceScope()
            ->whereIn('agent_id', $agentIds)
            ->where('status', 'indexed')
            ->selectRaw('type, count(*) as c')
            ->groupBy('type')
            ->pluck('c', 'type');

        // Slack lead alerts: count leads dispatched in the last 30 days where
        // routed_to recorded a Slack delivery (i.e. an integration was active
        // when the lead landed).
        $slackLeadsRecent = Lead::query()->withoutWorkspaceScope()
            ->whereIn('agent_id', $agentIds)
            ->where('routed_to', 'like', '%slack%')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $integrations = IntegrationConnection::query()
            ->where('workspace_id', $workspace->id)
            ->get()
            ->map(fn (IntegrationConnection $i) => [
                'id' => $i->id,
                'kind' => $i->kind,
                'is_configured' => $this->isConfigured($i),
                // For Slack we redact the webhook tail; for OAuth providers we
                // surface the workspace name so the user can confirm they
                // connected the right account.
                'webhook_hint' => $i->kind === 'slack'
                    ? $this->hintFor($i->credentials_encrypted['webhook_url'] ?? null)
                    : ($i->credentials_encrypted['workspace_name'] ?? null),
                'status' => $i->status,
                'last_sync_at' => $i->last_sync_at?->toIso8601String(),
                'summary' => $this->summaryFor($i->kind, $sourceCountsByType, $slackLeadsRecent),
            ]);

        // Platform-admin gate: when a kind is explicitly disabled in
        // AppSetting.integrations_enabled, hide its card on the buyer's
        // integrations page entirely. NULL / missing key = enabled.
        $enabledMap = (array) (AppSetting::query()
            ->find(AppSetting::SINGLETON_ID)?->integrations_enabled ?? []);
        $integrationKindEnabled = function (string $kind) use ($enabledMap): bool {
            if (! array_key_exists($kind, $enabledMap)) {
                return true;
            }

            return (bool) $enabledMap[$kind];
        };

        $settings = AppSetting::query()->find(AppSetting::SINGLETON_ID);

        return Inertia::render('app/integrations/index', [
            'integrations' => $integrations,
            'wordpressConnections' => $wordpressConnections,
            'integrationsEnabled' => [
                'slack' => $integrationKindEnabled('slack'),
                'notion' => $integrationKindEnabled('notion'),
                'google' => $integrationKindEnabled('google'),
                'webhooks' => $integrationKindEnabled('webhooks'),
                'wordpress' => $integrationKindEnabled('wordpress'),
            ],
            'wordpressPlugin' => [
                'download_url' => $settings?->wordpress_plugin_download_url ?: null,
                'help_text' => $settings?->wordpress_plugin_help_text ?: null,
            ],
            'webhookSubscriptions' => WebhookSubscription::query()
                ->where('workspace_id', $workspace->id)
                ->latest()
                ->get()
                ->map(fn (WebhookSubscription $subscription) => [
                    'id' => $subscription->id,
                    'url' => $subscription->url,
                    'host' => parse_url($subscription->url, PHP_URL_HOST) ?: null,
                    'enabled' => $subscription->enabled,
                    'events' => array_values(array_filter((array) $subscription->events, 'is_string')),
                    'event_labels' => $this->eventLabels((array) $subscription->events),
                    'secret_hint' => $this->secretHint($subscription->secret),
                    'created_at' => $subscription->created_at?->toIso8601String(),
                    'updated_at' => $subscription->updated_at?->toIso8601String(),
                ]),
            'webhookEventOptions' => collect($this->webhookEventOptions())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    public function storeSlack(Request $request, SlackPusher $slack, PlanLimits $limits): RedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        // Re-saving the same Slack webhook (the controller deletes the
        // existing row below) does NOT increase the count — only NEW
        // integrations do. We check before the delete, but if the
        // workspace already has a slack row it's a no-op replacement.
        $alreadyHasSlack = IntegrationConnection::query()
            ->where('workspace_id', $workspace->id)
            ->where('kind', 'slack')
            ->exists();

        if (! $alreadyHasSlack) {
            $check = $limits->check($workspace, PlanLimits::RESOURCE_INTEGRATION);
            if (! $check['allowed']) {
                return back()->with('error', $limits->reasonFor(PlanLimits::RESOURCE_INTEGRATION, (int) $check['limit']));
            }
        }

        $data = $request->validate([
            'webhook_url' => ['required', 'url', 'max:500', 'starts_with:https://hooks.slack.com/'],
            'send_test' => ['nullable', 'boolean'],
        ]);

        IntegrationConnection::query()
            ->where('workspace_id', $workspace->id)
            ->where('kind', 'slack')
            ->delete();

        IntegrationConnection::create([
            'workspace_id' => $workspace->id,
            'kind' => 'slack',
            'credentials_encrypted' => ['webhook_url' => $data['webhook_url']],
            'status' => 'active',
        ]);

        if (! empty($data['send_test'])) {
            // Buyer-reported (Lucian, 2026-05-15): the canary "lead" we
            // post on test-send was hardcoded to pitchbar-test@example.com.
            // Operators panicked thinking a real visitor came through.
            // Use the workspace name + the authenticated user's identity
            // so the test alert is obviously a self-test and reaches the
            // operator's inbox under their own name.
            $tester = $request->user();
            // Build the fallback email defensively: when `app.url` is
            // null / empty (mis-configured install) `parse_url` returns
            // null and the email would render as `test@` — invalid.
            // Anchor the fallback domain on the workspace slug so the
            // alert is still readable + a routable shape.
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);
            $fallbackHost = is_string($host) && $host !== ''
                ? $host
                : 'pitchbar.local';
            $fake = new Lead;
            $fake->forceFill([
                'id' => 'test-'.bin2hex(random_bytes(4)),
                'email' => (string) ($tester?->email ?: 'test@'.$fallbackHost),
                'name' => trim(($tester?->name ?: 'Test').' (Slack test from '.$workspace->name.')'),
                'phone' => null,
            ]);
            // SlackPusher::pushLead returns bool — catches its own network
            // errors and returns false. We surface that as a flash error
            // so the operator doesn't see "Slack connected" success +
            // an empty Slack channel and assume the integration works.
            try {
                $delivered = $slack->pushLead($data['webhook_url'], $fake);
            } catch (\Throwable $e) {
                return back()->with('error', 'Slack connected, but the test message failed: '.$e->getMessage());
            }
            if (! $delivered) {
                return back()->with('error', 'Slack connected, but the test message did not deliver. Check that the webhook URL is still active.');
            }
        }

        return back()->with('success', 'Slack connected.');
    }

    public function storeWebhook(Request $request, PlanLimits $limits): RedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $check = $limits->check($workspace, PlanLimits::RESOURCE_INTEGRATION);
        if (! $check['allowed']) {
            return back()->with('error', $limits->reasonFor(PlanLimits::RESOURCE_INTEGRATION, (int) $check['limit']));
        }

        $data = $this->validateWebhookPayload($request, true);

        WebhookSubscription::create([
            'workspace_id' => $workspace->id,
            'url' => $data['url'],
            'secret' => $data['secret'],
            'events' => $data['events'],
            'enabled' => (bool) ($data['enabled'] ?? false),
        ]);

        return back()->with('success', 'Webhook subscription added.');
    }

    public function updateWebhook(Request $request, WebhookSubscription $webhookSubscription): RedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        abort_unless($webhookSubscription->workspace_id === $workspace->id, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $data = $this->validateWebhookPayload($request, false);

        $payload = [
            'url' => $data['url'],
            'events' => $data['events'],
            'enabled' => (bool) ($data['enabled'] ?? false),
        ];

        if (($data['secret'] ?? '') !== '') {
            $payload['secret'] = $data['secret'];
        }

        $webhookSubscription->fill($payload)->save();

        return back()->with('success', 'Webhook subscription updated.');
    }

    public function destroy(Request $request, IntegrationConnection $integration): RedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        abort_unless($integration->workspace_id === $workspace->id, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $integration->delete();

        return back()->with('success', 'Integration disconnected.');
    }

    public function destroyWebhook(Request $request, WebhookSubscription $webhookSubscription): RedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        abort_unless($webhookSubscription->workspace_id === $workspace->id, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $webhookSubscription->delete();

        return back()->with('success', 'Webhook subscription removed.');
    }

    public function rotateWebhookSecret(Request $request, WebhookSubscription $webhookSubscription): RedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        abort_unless($webhookSubscription->workspace_id === $workspace->id, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $newSecret = 'whsec_'.Str::random(40);
        $webhookSubscription->forceFill(['secret' => $newSecret])->save();

        AuditLog::create([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'action' => 'integration.webhook_secret_rotated',
            'entity_type' => 'webhook_subscription',
            'entity_id' => $webhookSubscription->id,
            'before' => [],
            'after' => ['rotated_at' => now()->toIso8601String()],
            'ip' => $request->ip(),
            'ua' => substr((string) $request->userAgent(), 0, 240),
            'created_at' => now(),
        ]);

        return back()->with([
            'success' => 'Webhook secret rotated. Copy the new secret now — it will not be shown again.',
            'rotated_secret' => $newSecret,
            'rotated_subscription_id' => $webhookSubscription->id,
        ]);
    }

    /**
     * Per-provider summary string for the Integrations page card.
     * Returns null when there's nothing useful to surface yet.
     */
    private function summaryFor(string $kind, Collection $sourceCountsByType, int $slackLeadsRecent): ?string
    {
        return match ($kind) {
            'notion' => ($n = (int) $sourceCountsByType->get('notion', 0)) > 0
                ? $n.' '.Str::plural('Notion page', $n).' indexed'
                : null,
            'google' => ($n = (int) $sourceCountsByType->get('google_doc', 0)) > 0
                ? $n.' Google '.Str::plural('Doc', $n).' indexed'
                : null,
            'slack' => $slackLeadsRecent > 0
                ? $slackLeadsRecent.' '.Str::plural('lead', $slackLeadsRecent).' alerted in last 30d'
                : null,
            default => null,
        };
    }

    private function isConfigured(IntegrationConnection $i): bool
    {
        $creds = (array) ($i->credentials_encrypted ?? []);

        return match ($i->kind) {
            'slack' => is_string($creds['webhook_url'] ?? null),
            'notion', 'google' => is_string($creds['access_token'] ?? null),
            default => false,
        };
    }

    private function hintFor(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        // …/services/T01ABC/B02DEF/<secret>  → show only T01ABC/B02DEF
        if (preg_match('#hooks\.slack\.com/services/([^/]+)/([^/]+)/#', $url, $m) === 1) {
            return $m[1].'/'.$m[2];
        }

        return mb_substr($url, 0, 30).'…';
    }

    /**
     * @return array<int, string>
     */
    private function eventLabels(array $events): array
    {
        $options = $this->webhookEventOptions();

        return array_values(array_map(
            static fn (string $event) => $options[$event] ?? $event,
            array_values(array_filter($events, 'is_string')),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function webhookEventOptions(): array
    {
        return [
            'lead.captured' => 'Lead captured',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateWebhookPayload(Request $request, bool $requireSecret): array
    {
        return $request->validate([
            'url' => ['required', 'url', 'max:1000'],
            'secret' => [
                $requireSecret ? 'required' : 'nullable',
                'string',
                'min:12',
                'max:128',
            ],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', Rule::in(array_keys($this->webhookEventOptions()))],
            'enabled' => ['sometimes', 'boolean'],
        ]);
    }

    private function secretHint(string $secret): string
    {
        $len = mb_strlen($secret);

        if ($len <= 6) {
            return str_repeat('•', $len);
        }

        return str_repeat('•', max(4, $len - 4)).mb_substr($secret, -4);
    }
}
