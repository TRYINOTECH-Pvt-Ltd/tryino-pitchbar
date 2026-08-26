<?php

namespace App\Listeners\Leads;

use App\Events\Leads\LeadCapturedEvent;
use App\Models\Lead;
use App\Models\Source;
use App\Models\WorkspaceApiToken;
use App\Scopes\WorkspaceScope;
use App\Support\HmacSignature;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors a freshly-captured Pitchbar Lead back into the WordPress
 * site behind the chat agent. Triggered after `LeadCapturedEvent`
 * fires (well after the SSE stream has finished). Best-effort:
 * transport failures are logged but never bubble up to the user.
 *
 * Resolves the destination by looking at the lead's agent's WordPress
 * or WooCommerce source. No matching source -> silent no-op (covers
 * agents that don't run on WP at all).
 */
class PushLeadToWordPress implements ShouldQueue
{
    public string $queue = 'integrations';

    public int $tries = 3;

    public int $backoff = 30;

    public function handle(LeadCapturedEvent $event): void
    {
        // CLAUDE.md §2 justification: queue listener has no
        // authenticated request → CurrentWorkspace is null → the
        // BelongsToAgent global scope wouldn't bind. We trust the
        // event payload (which carries the leadId from the same
        // workspace's controller that emitted the event) and use the
        // lead's own agent_id to drive subsequent lookups. No
        // cross-tenant data path.
        $lead = Lead::query()
            ->withoutGlobalScopes()
            ->find($event->leadId);
        if ($lead === null) {
            return;
        }

        $source = $this->resolveWpSource($lead);
        if ($source === null) {
            return;
        }

        $signingSecret = $this->resolveSigningSecret($event->workspaceId);
        if ($signingSecret === null) {
            return;
        }

        $baseUrl = rtrim((string) ($source->config['site_url'] ?? ''), '/');
        if ($baseUrl === '') {
            return;
        }

        $payload = [
            'pitchbar_lead_id' => (string) $lead->id,
            'conversation_id' => (string) $lead->conversation_id,
            'email' => (string) $lead->email,
            'name' => $lead->name,
            'phone' => $lead->phone,
            'fields' => $lead->fields ?? [],
        ];

        $body = (string) json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = HmacSignature::sign($signingSecret, $body);

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'X-Pitchbar-Signature' => $signature,
                    'Accept' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($baseUrl.'/wp-json/pitchbar/v1/leads');
        } catch (\Throwable $e) {
            Log::warning('PushLeadToWordPress transport error', [
                'lead_id' => $event->leadId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $response->successful()) {
            Log::warning('PushLeadToWordPress rejected by plugin', [
                'lead_id' => $event->leadId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }
    }

    private function resolveWpSource(Lead $lead): ?Source
    {
        // CLAUDE.md §2 justification: explicit `agent_id =` clause is
        // the tenancy guarantee; Source.agent_id is workspace-scoped.
        // Queue context has no CurrentWorkspace bound, so global scope
        // would no-op anyway.
        return Source::query()
            ->withoutGlobalScopes()
            ->where('agent_id', $lead->agent_id)
            ->whereIn('type', ['woocommerce_products', 'wordpress'])
            ->orderByDesc('last_synced_at')
            ->first();
    }

    private function resolveSigningSecret(string $workspaceId): ?string
    {
        $token = WorkspaceApiToken::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->whereNull('revoked_at')
            ->whereNotNull('shopper_signing_secret')
            ->orderByDesc('last_used_at')
            ->first(['shopper_signing_secret']);

        return $token === null ? null : (string) $token->shopper_signing_secret;
    }
}
