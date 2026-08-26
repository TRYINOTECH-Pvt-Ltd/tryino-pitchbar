<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\WorkspaceApiToken;
use App\Services\Billing\PlanLimits;
use App\Support\AuditLogger;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace-scoped API tokens for first-party integrations like the
 * WordPress companion plugin. Plaintext is shown once in the flash
 * payload on create; only the sha256 hash is persisted. Revoking
 * stamps revoked_at — rows stay for audit instead of hard-deleting.
 */
class WorkspaceApiTokenController extends Controller
{
    private const SUPPORTED_ABILITIES = [
        'wp:integration',
        'sources:write',
    ];

    public function __construct(
        private readonly CurrentWorkspace $current,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workspace();
        abort_unless($request->user()->can('viewAny', [WorkspaceApiToken::class, $workspace]), 403);

        $tokens = WorkspaceApiToken::query()
            ->where('workspace_id', $workspace->id)
            ->with('createdBy:id,name,email')
            ->latest()
            ->get(['id', 'name', 'abilities', 'last_used_at', 'revoked_at', 'created_at', 'created_by_user_id']);

        return Inertia::render('settings/api-tokens', [
            'tokens' => $tokens,
            'abilities' => self::SUPPORTED_ABILITIES,
            'new_token' => $request->session()->get('new_token'),
        ]);
    }

    public function store(Request $request, PlanLimits $limits): RedirectResponse
    {
        $workspace = $this->workspace();
        abort_unless($request->user()->can('manage', [WorkspaceApiToken::class, $workspace]), 403);

        // API access can be plan-gated. Pre-existing plans default to
        // api_access=true so legacy tokens keep working; admins opt out
        // by toggling the flag on the Free / cheaper tier.
        if (! $limits->apiAccessEnabled($workspace)) {
            return back()->with('error', 'API access is not included on your current plan. Upgrade to mint tokens.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(self::SUPPORTED_ABILITIES)],
        ]);

        // Prefix lets operators eyeball whether a leaked secret belongs
        // to Pitchbar at all before they go digging for the workspace.
        $plaintext = 'pbar_'.Str::random(48);

        // Per-token signing secret for short-lived shopper tokens
        // (CMS adapters use it to sign visitor-context claims). Encrypted
        // at rest via the model's `encrypted` cast and read back decrypted
        // when signing (see ShopperToken). The cast's envelope is ~250+
        // chars, so the column is TEXT, not the plaintext-era VARCHAR(64).
        $shopperSigningSecret = Str::random(48);

        $token = WorkspaceApiToken::create([
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $request->user()?->id,
            'name' => $data['name'],
            'token_hash' => hash('sha256', $plaintext),
            'abilities' => array_values(array_unique($data['abilities'])),
            'shopper_signing_secret' => $shopperSigningSecret,
        ]);

        AuditLogger::log(
            workspaceId: $workspace->id,
            action: 'api_token.created',
            entityType: 'workspace_api_token',
            entityId: $token->id,
            after: ['name' => $token->name, 'abilities' => $token->abilities],
            request: $request,
        );

        return back()
            ->with('success', 'API token created. Copy it now — it will not be shown again.')
            ->with('new_token', $plaintext);
    }

    public function destroy(Request $request, WorkspaceApiToken $token): RedirectResponse
    {
        abort_unless($request->user()->can('revoke', $token), 403);

        if ($token->revoked_at === null) {
            $token->forceFill(['revoked_at' => now()])->save();

            AuditLogger::log(
                workspaceId: $token->workspace_id,
                action: 'api_token.revoked',
                entityType: 'workspace_api_token',
                entityId: $token->id,
                before: ['name' => $token->name],
                request: $request,
            );
        }

        return back()->with('success', 'Token revoked.');
    }

    /**
     * Hard-delete the row so it disappears from the list. Used when an
     * admin wants to tidy up the token table (revoked tokens piling up,
     * one-off integrations no longer in use). Buyer-reported 2026-05-20.
     */
    public function forceDestroy(Request $request, WorkspaceApiToken $token): RedirectResponse
    {
        abort_unless($request->user()->can('revoke', $token), 403);

        $snapshot = ['name' => $token->name, 'workspace_id' => $token->workspace_id];

        // Revoke first so a brief window where the row is gone but the
        // SHA hash is still in flight on an in-progress request cannot
        // be considered authoritative — Sanctum-style guards check
        // revoked_at before lookup. Then hard-delete the row.
        if ($token->revoked_at === null) {
            $token->forceFill(['revoked_at' => now()])->save();
        }
        $workspaceId = $token->workspace_id;
        $tokenId = $token->id;
        $token->delete();

        AuditLogger::log(
            workspaceId: $workspaceId,
            action: 'api_token.forgotten',
            entityType: 'workspace_api_token',
            entityId: $tokenId,
            before: $snapshot,
            request: $request,
        );

        return back()->with('success', 'Token forgotten.');
    }

    /**
     * Bulk hard-delete every revoked token for the current workspace.
     * Useful when revoked tokens have accumulated over months and the
     * list becomes noise. Active (non-revoked) tokens are never touched.
     */
    public function purgeRevoked(Request $request): RedirectResponse
    {
        $workspace = $this->workspace();
        abort_unless($request->user()->can('manage', [WorkspaceApiToken::class, $workspace]), 403);

        $purged = WorkspaceApiToken::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('revoked_at')
            ->delete();

        AuditLogger::log(
            workspaceId: $workspace->id,
            action: 'api_token.purged',
            entityType: 'workspace_api_token',
            entityId: null,
            after: ['count' => (int) $purged],
            request: $request,
        );

        return back()->with('success', "Purged {$purged} revoked token(s).");
    }

    private function workspace(): Workspace
    {
        $workspace = $this->current->get();
        abort_unless($workspace !== null, 404);

        return $workspace;
    }
}
