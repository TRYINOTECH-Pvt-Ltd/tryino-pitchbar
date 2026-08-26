<?php

namespace App\Http\Controllers\Admin;

use App\Models\IntegrationConnection;
use App\Services\Integrations\Notion\NotionClient;
use App\Services\Integrations\Notion\NotionException;
use App\Support\CurrentWorkspace;
use App\Support\OAuthState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Notion OAuth start + callback. The actual ingestion of pages happens via
 * NotionSourceController::store + IngestNotionPageJob — this controller's
 * only job is to put the encrypted access_token into IntegrationConnection.
 */
class NotionOAuthController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * Redirect to Notion's authorize URL with a CSRF-style state pinned to
     * the workspace + a fresh nonce.
     */
    public function start(Request $request): RedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $clientId = (string) config('services.notion.client_id');
        $redirect = (string) config('services.notion.redirect_uri');
        if ($clientId === '' || $redirect === '') {
            return redirect('/app/integrations')
                ->with('error', 'Notion is not configured. Set NOTION_CLIENT_ID, NOTION_CLIENT_SECRET, NOTION_REDIRECT_URI in .env.');
        }

        $state = OAuthState::start($request, 'notion', $workspace->id);

        $authorize = 'https://api.notion.com/v1/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'owner' => 'user',
            'state' => $state,
        ]);

        return redirect()->away($authorize);
    }

    public function callback(Request $request, NotionClient $notion): RedirectResponse
    {
        $workspaceId = OAuthState::consume($request, 'notion', (string) $request->query('state', ''));
        if ($workspaceId === null) {
            return redirect('/app/integrations')->with('error', 'Notion connection failed: bad or expired state. Try again.');
        }

        if ($request->query('error')) {
            return redirect('/app/integrations')->with('error', 'Notion declined: '.$request->query('error'));
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect('/app/integrations')->with('error', 'Notion connection failed: no code returned.');
        }

        try {
            $tokens = $notion->exchangeCode(
                $code,
                (string) config('services.notion.redirect_uri'),
            );
        } catch (NotionException $e) {
            return redirect('/app/integrations')->with('error', 'Notion: '.$e->getMessage());
        }

        IntegrationConnection::query()
            ->where('workspace_id', $workspaceId)
            ->where('kind', 'notion')
            ->delete();

        IntegrationConnection::create([
            'workspace_id' => $workspaceId,
            'kind' => 'notion',
            'credentials_encrypted' => [
                'access_token' => $tokens['access_token'],
                'workspace_id' => $tokens['workspace_id'],
                'workspace_name' => $tokens['workspace_name'],
                'bot_id' => $tokens['bot_id'],
            ],
            'status' => 'active',
        ]);

        return redirect('/app/integrations')->with('success', 'Notion connected.');
    }
}
