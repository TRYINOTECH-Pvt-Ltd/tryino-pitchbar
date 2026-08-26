<p>
    Integrations let your agent learn from data living outside the
    Pitchbar database (Notion, Google Docs) and let leads flow into your
    existing systems (CRMs, Slack, webhooks). Open
    <code>/app/integrations</code> to manage them.
</p>

<h2>Notion</h2>

<p>
    Connect once via OAuth. Pitchbar requests read access to the workspaces
    you select during the OAuth flow — we never get global access. After
    connecting:
</p>

<ul>
    <li>The <strong>Add source</strong> modal exposes a Notion picker (page or database).</li>
    <li>Each picked page becomes a Notion source and is ingested via <code>IngestNotionPageJob</code>.</li>
    <li>Re-syncs are manual (per-source <strong>Reindex</strong>) — we don't poll Notion on a schedule.</li>
    <li>The OAuth token is encrypted at rest using Laravel's <code>encrypted</code> cast.</li>
</ul>

<h2>Google Docs</h2>

<p>
    Same shape as Notion. OAuth-once, pick docs from a Drive picker, ingest
    via <code>IngestGoogleDocJob</code>, manual re-sync per source. Tokens
    encrypted at rest. Disconnect at any time — disconnecting revokes our
    access immediately and prevents further syncs.
</p>

<p>
    When Google reports the refresh token <strong>expired or
    revoked</strong> (the user revoked access, or a test-mode OAuth app
    aged its tokens out), the connection flips to
    <code>expired</code>: the Integrations page shows the reconnect
    state, adding new Google sources is blocked with a clear message,
    and any failing Google Doc source shows "Reconnect Google under
    Integrations, then click Reindex" instead of a generic error. After
    reconnecting, one <em>Reindex</em> on the source recovers it —
    sources are never deleted by an expired connection.
</p>

<h2>Slack</h2>

<p>
    Slack is for outgoing notifications:
</p>

<ul>
    <li>New leads — posts to a configurable channel.</li>
    <li>Routed conversations — pings when the inbox needs a human.</li>
    <li>Daily digest — opt-in summary of conversation volume + new gaps.</li>
</ul>

<p>
    Connect via OAuth, pick the channel, save. The bot posts under the
    integration's name, never as a user.
</p>

<p>
    Ticking <strong>Send test</strong> on save posts a sample "New lead"
    message to the channel so you can confirm the wiring before a real
    visitor submits. The test payload uses the authenticated user's
    name and email (suffixed with "Slack test from &lt;workspace
    name&gt;") so the alert is obviously a self-test — operators won't
    panic thinking a real lead came through.
</p>

<h2>Webhooks (outgoing)</h2>

<p>
    Pitchbar can POST to your endpoint when events happen. Configure under
    <code>/app/integrations/webhooks</code>. Events available:
</p>

<table>
    <thead><tr><th>Event</th><th>Fires when</th></tr></thead>
    <tbody>
        <tr><td><code>lead.captured</code></td><td>The widget lead form was submitted (the only event currently shipped).</td></tr>
    </tbody>
</table>

<p>
    The other event names you might see in older roadmap notes
    (<code>conversation.started</code>, <code>conversation.message</code>,
    <code>conversation.routed</code>, <code>lead.updated</code>) are
    on the roadmap but not yet wired. Add additional events by
    extending <code>SignedDispatcher</code>.
</p>

<p>
    Each webhook has a signing secret. Pitchbar HMACs the body with that
    secret and sends the digest in the <code>X-Pitchbar-Signature</code>
    header — verify it on receipt. The lead-captured dispatcher
    (<code>app/Services/Webhooks/SignedDispatcher.php</code>) is
    single-attempt by design (the lead is already persisted; a failed
    webhook delivery surfaces in the workflow run log rather than
    blocking the visitor's submission). Workflow-step webhooks
    (<code>DispatchWebhookJob</code>) retry up to 3 times via Laravel's
    queue retry mechanism.
</p>

<p>
    See <a href="/documentation/webhooks">Outgoing webhooks</a> for the
    payload shapes.
</p>

<h2>HubSpot / Salesforce / Zapier</h2>

<p>
    The webhooks above are the universal escape hatch — they work with
    anything that can receive HTTP POSTs. Native HubSpot and Salesforce
    integrations are on the roadmap; in the meantime, point a webhook at a
    Zapier catch-hook and let Zapier route to your CRM.
</p>

<h2>Disconnecting</h2>

<p>
    Each integration's row has a <strong>Disconnect</strong> button. We:
</p>

<ul>
    <li>Revoke our OAuth token with the upstream provider (Notion / Google).</li>
    <li>Mark the local <code>integration_connection</code> row as inactive.</li>
    <li>Stop syncing — sources backed by the integration enter an "orphaned" state and stop refreshing, but their already-indexed content stays usable.</li>
</ul>

<p>
    Reconnecting re-runs the OAuth flow and re-binds the existing sources.
    No data is lost.
</p>

<h2>Permissions</h2>

<p>
    Connecting an integration requires the <code>integrations.manage</code>
    permission, which is granted to Owners and Admins. Members can see
    which integrations are connected but can't change them.
</p>
