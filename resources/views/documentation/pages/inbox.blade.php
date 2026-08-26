<p>
    The Inbox is your lead-and-takeover console. <code>/app/inbox</code>
    lists every captured lead; opening one shows the full conversation
    transcript that produced it and lets you jump in as a human operator
    to continue the thread.
</p>

<h2>Layout</h2>

<p>
    <code>/app/inbox</code> shows leads on the left, sorted by recency.
    Click one and the end-hand pane shows the conversation that
    produced it: visitor messages on one side, agent replies on the
    other, human-agent messages from past takeovers in their own role
    (<code>user</code> / <code>assistant</code> / <code>human-agent</code>).
</p>

<p>
    The Status column is an inline select — change it in place to
    move a lead through new → qualified → contacted → won / lost.
    The list also auto-refetches when the tab regains focus or you
    navigate back from a conversation thread, so a status edit you
    made elsewhere shows up without a manual reload.
</p>

<p>
    The conversations index at <code>/app/conversations</code> covers
    every conversation regardless of whether it produced a lead — useful
    for spelunking past sessions that didn't convert. From there you can
    open a conversation and use the same takeover controls.
</p>

<h2>Live updates</h2>

<p>
    Every open conversation subscribes to its private Reverb channel
    (<code>conversation.{id}</code>) for real-time updates. New messages
    appear without polling; the takeover state propagates to both the
    visitor's widget and any other operator looking at the same thread.
</p>

<h2>Taking over</h2>

<p>
    Click <strong>Take over</strong>. A few things happen:
</p>

<ol>
    <li>The conversation gets <code>claimed_by_user_id</code> + <code>claimed_at</code> set to you (route: <code>POST /app/conversations/{conversation}/claim</code>).</li>
    <li>A <code>conversation.claimed</code> Reverb event fires on the conversation's private channel — the visitor's widget shows "Human is here" in the chat header.</li>
    <li>The AI is paused. Every visitor message routes to the inbox; every reply you type streams to the visitor as a <code>human-agent</code> role message via <code>POST /app/conversations/{conversation}/reply</code>.</li>
</ol>

<h2>Releasing</h2>

<p>
    Click <strong>Hand back to bot</strong> to release (route:
    <code>POST /app/conversations/{conversation}/release</code>). The next
    visitor message goes through the RAG pipeline again. The visitor's
    chat header flips back to the agent's persona. Useful when:
</p>

<ul>
    <li>You answered the off-script question and the rest is back to FAQ territory.</li>
    <li>The visitor is satisfied and likely to leave.</li>
    <li>You're ending your shift — handing back keeps coverage 24/7.</li>
</ul>

<h2>Capturing leads</h2>

<p>
    Leads come in two ways:
</p>

<ul>
    <li><strong>Visitor-driven</strong> — the widget's inline lead form, fired by a behavior rule or by the visitor explicitly asking to be contacted. Submitted leads land in <code>/app/inbox</code>.</li>
    <li><strong>Webhook-driven</strong> — every captured lead fires the <code>lead.captured</code> outgoing webhook so you can fan it into your CRM. See <a href="/documentation/webhooks">Outgoing webhooks</a>.</li>
</ul>

<h2>Live in-app toasts</h2>

<p>
    The admin shell polls <code>GET /app/leads/feed</code> every 30
    seconds for newly captured leads in the workspace and surfaces each
    one as a sonner toast in the bottom-right of every page in the
    admin SPA. Click the toast to jump straight to the inbox row.
</p>

<p>
    The bell button in the top header asks the browser for native
    notification permission. Once granted, every new lead also fires
    an OS-level notification so workspace members get pinged on tabs
    that aren't focused. Permission is per-domain — denying it once
    can only be reversed from your browser's settings.
</p>

<p>
    Polling pauses on hidden tabs to keep idle dashboards from burning
    HTTP. The cursor lives in <code>sessionStorage</code> so opening a
    second tab doesn't double-toast already-seen leads.
</p>

<h2>Email notifications</h2>

<p>
    Every captured lead fans out to every workspace owner and admin
    over email. The notification (<code>App\Notifications\NewLeadCaptured</code>)
    is queued — the visitor's HTTP request never waits on SMTP, so a
    slow mailer cannot slow lead capture or chat.
</p>

<p>
    Two requirements for the email to actually arrive:
</p>

<ol>
    <li>
        A queue worker is running. In production we use the
        <code>database</code> driver — make sure
        <code>php artisan queue:work --queue=default</code> runs on a
        process supervisor (the in-cluster worker takes care of this on
        Laravel Cloud). Without it, queued notifications pile up in
        <code>jobs</code> and never send.
    </li>
    <li>
        <code>MAIL_MAILER</code> + matching credentials are configured
        in <code>.env</code>. The default is <code>log</code> — fine for
        dev but no actual email is sent. Switch to <code>smtp</code> /
        <code>resend</code> / <code>postmark</code> in production and
        verify with <code>php artisan tinker --execute 'Mail::raw("ping",fn($m)=>$m-&gt;to("you@example.com")-&gt;subject("test"));'</code>.
    </li>
</ol>

<p>
    Recipients are filtered down to <code>workspace_users.role IN
    ('owner', 'admin')</code> with <code>accepted_at IS NOT NULL</code>.
    Pending invites and viewers do not receive lead emails. The footer
    of the email reflects your white-labelled site title (set in
    Settings → System).
</p>

<h2>Cleaning up the conversation log</h2>

<p>
    A new <code>Conversation</code> row is written every time a visitor
    loads the widget for the first time in 24 hours. Visitors that drive
    by without typing still produce a row, which means
    <code>/app/conversations</code> can pile up with empty sessions over
    time. Two affordances keep it manageable:
</p>

<ul>
    <li>
        <strong>Engaged-only filter</strong> (default). The list hides
        conversations with no visitor messages. Toggle <strong>Show
        all</strong> in the filter bar to see every session — useful when
        you're looking for bot traffic or QA loads.
    </li>
    <li>
        <strong>Per-row delete</strong>. Admin and Owner roles see a
        trash icon on hover. Deleting cascades to messages, leads, and
        applied tags via DB foreign keys; analytics events are kept
        (FK <code>nullOnDelete</code>) so aggregate stats stay intact.
    </li>
    <li>
        <strong>Bulk delete empty</strong>. The button in the filter bar
        wipes every conversation in the workspace with no user-role
        message. Sessions where the visitor sent at least one message
        are never touched.
    </li>
    <li>
        <strong>Bulk delete selected</strong>. Tick the checkboxes on
        any visible rows, then click <strong>Delete N selected</strong>.
        The workspace global scope on <code>Conversation</code> protects
        cross-tenant ID smuggling — IDs from other workspaces are
        silently dropped server-side.
    </li>
</ul>

<p>
    Viewers and Editors do not see delete affordances; the
    <code>ConversationPolicy::delete</code> +
    <code>WorkspacePolicy::bulkDeleteConversations</code> checks require
    Admin or Owner. Deletion is irreversible — there is no soft-delete
    trail and the audit log does not yet capture conversation removals.
</p>

<h2>Audit log</h2>

<p>
    Privileged actions on conversations (claim, release) write rows to
    the <code>audit_logs</code> table for forensic traceability. There's
    no UI page for browsing them in v1; query the table directly when
    you need to investigate.
</p>
