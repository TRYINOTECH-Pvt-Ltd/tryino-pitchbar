<?php

namespace App\Http\Controllers\Admin;

use App\Models\IntegrationConnection;
use App\Services\Integrations\Google\GoogleClient;
use App\Services\Integrations\Google\GoogleException;
use App\Support\CurrentWorkspace;
use App\Support\OAuthState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Google OAuth start + callback. Asks for read-only scopes on Drive +
 * Docs. The encrypted access_token + refresh_token land in
 * IntegrationConnection.credentials_encrypted.
 */
class GoogleOAuthController
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/drive.readonly',
        'https://www.googleapis.com/auth/documents.readonly',
        // C8b: Sheets ingest. Existing OAuth connections re-prompt
        // for consent the next time they reach this scope; the OAuth
        // start flow always requests the full set so a fresh connect
        // covers Sheets without a second round trip.
        'https://www.googleapis.com/auth/spreadsheets.readonly',
    ];

    public function __construct(private readonly CurrentWorkspace $current) {}

    public function start(Request $request): RedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $clientId = (string) config('services.google.client_id');
        $redirect = (string) config('services.google.redirect_uri');
        if ($clientId === '' || $redirect === '') {
            return redirect('/app/integrations')
                ->with('error', 'Google is not configured. Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI in .env.');
        }

        $state = OAuthState::start($request, 'google', $workspace->id);

        $authorize = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            // offline + prompt=consent: ensure we get a refresh_token even on
            // re-auth (Google omits it on subsequent grants by default).
            'access_type' => 'offline',
            'prompt' => 'consent',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'include_granted_scopes' => 'true',
        ]);

        return redirect()->away($authorize);
    }

    public function callback(Request $request, GoogleClient $google): RedirectResponse
    {
        $workspaceId = OAuthState::consume($request, 'google', (string) $request->query('state', ''));
        if ($workspaceId === null) {
            return redirect('/app/integrations')->with('error', 'Google connection failed: bad or expired state. Try again.');
        }

        if ($request->query('error')) {
            return redirect('/app/integrations')->with('error', 'Google declined: '.$request->query('error'));
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect('/app/integrations')->with('error', 'Google connection failed: no code returned.');
        }

        try {
            $tokens = $google->exchangeCode(
                $code,
                (string) config('services.google.redirect_uri'),
            );
        } catch (GoogleException $e) {
            return redirect('/app/integrations')->with('error', 'Google: '.$e->getMessage());
        }

        IntegrationConnection::query()
            ->where('workspace_id', $workspaceId)
            ->where('kind', 'google')
            ->delete();

        IntegrationConnection::create([
            'workspace_id' => $workspaceId,
            'kind' => 'google',
            'credentials_encrypted' => [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_at' => now()->addSeconds($tokens['expires_in'])->toIso8601String(),
                'scope' => $tokens['scope'],
            ],
            'status' => 'active',
        ]);

        return redirect('/app/integrations')->with('success', 'Google Drive connected.');
    }
}
