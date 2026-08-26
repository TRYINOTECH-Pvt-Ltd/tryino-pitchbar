<p>
    Every conversation gets a <strong>lead score</strong> on a 0–100 scale
    derived from observable signals: which pages the visitor browsed, how
    deeply they engaged in chat, and whether they shared contact info.
    The score appears as a coloured pill on the conversations list, the
    leads list, the inbox, and inside each conversation's right rail —
    so sales can prioritise the hot prospects without re-reading every
    transcript. Webhook consumers also get the score in the
    <code>lead.captured</code> payload, so a CRM can route hot leads to
    a different queue automatically.
</p>

<h2>How the score is computed</h2>

<p>
    The <code>LeadScoringEngine</code> sums four capped weights, clamps the
    total to <code>[0, 100]</code>, then maps the result onto three
    buckets:
</p>

<table>
    <thead>
        <tr><th>Signal</th><th>Weight</th><th>Cap</th></tr>
    </thead>
    <tbody>
        <tr>
            <td>Unique pages visited</td>
            <td>+5 per distinct URL</td>
            <td>25</td>
        </tr>
        <tr>
            <td>
                Intent pages (URL contains pricing, demo, contact, buy,
                signup, checkout, cart, purchase, order)
            </td>
            <td>+15 per matching page</td>
            <td>30</td>
        </tr>
        <tr>
            <td>Chat engagement (messages exchanged)</td>
            <td>+2 per message</td>
            <td>30</td>
        </tr>
        <tr>
            <td>Lead captured (email present)</td>
            <td>+15 flat</td>
            <td>15</td>
        </tr>
    </tbody>
</table>

<p>
    Bucket thresholds: <strong>Hot</strong> ≥ 70 (green),
    <strong>Warm</strong> 40–69 (amber), <strong>Cold</strong> 0–39 (grey).
    A visitor who lands once, sends two messages, and disappears scores
    9 (Cold). A visitor who hits four pages including <code>/pricing</code>,
    chats for 8 turns, and submits their email scores 75 (Hot).
</p>

<h2>How it stays fresh</h2>

<p>
    Recompute is queued — never on the SSE hot path. The
    <code>RecomputeLeadScoreJob</code> fires from three places:
</p>

<ul>
    <li>
        <strong>New page view</strong> —
        <code>/api/v1/widget/init</code> writes a
        <code>visitor_page_views</code> row each time the widget boots
        on a fresh page (same-page reloads within 2 minutes are deduped),
        then enqueues a recompute.
    </li>
    <li>
        <strong>New turn persisted</strong> — <code>PersistTurnJob</code>
        appends every assistant + user turn to the messages table and
        enqueues a recompute on its way out.
    </li>
    <li>
        <strong>Lead captured</strong> — when the visitor submits the
        lead form the controller runs the recompute synchronously
        <em>before</em> dispatching <code>RouteLeadJob</code>, so the
        webhook payload sees the up-to-date score.
    </li>
</ul>

<h2>Visitor trajectory</h2>

<p>
    Behind the score sits a per-visitor browsing trail. Every page the
    widget mounts on records a row in <code>visitor_page_views</code>:
</p>

<pre><code>id           bigserial
workspace_id uuid       -- tenancy scope
agent_id     uuid
visitor_id   uuid
conversation_id uuid    -- nullable (set when the page view happens
                        --           inside an active conversation)
url          varchar(500)
title        varchar(200) nullable
referrer     varchar(500) nullable
viewed_at    timestamptz
created_at   timestamptz</code></pre>

<p>
    The conversation detail page (<code>/app/conversations/{id}</code>)
    renders the last 50 page views in chronological order in the right
    rail — pages visited <em>during</em> the conversation get a green
    border so the operator can tell mid-chat browsing from earlier
    visits.
</p>

<h2>Webhook payload</h2>

<p>
    The <code>lead.captured</code> event now ships with three extra
    fields:
</p>

<pre><code>{
  "event": "lead.captured",
  "lead": { "id": "01h…", "email": "buyer@example.com", … },
  "agent_id": "01h…",
  "score": 75,
  "score_bucket": "high",
  "trajectory": [
    {
      "url": "https://example.com/pricing",
      "title": "Pricing — Plans",
      "referrer": "https://google.com",
      "viewed_at": "2026-05-18T10:14:02+00:00"
    },
    …
  ]
}</code></pre>

<p>
    The trajectory is capped at the 10 most recent views to keep
    payloads small. <code>score_bucket</code> is one of
    <code>low</code>, <code>medium</code>, <code>high</code>.
</p>

<h2>Why this score? (reasons array)</h2>

<p>
    Every recompute also persists the line-by-line breakdown into
    <code>conversations.lead_score_reasons</code> (JSON). Each row is a
    human-readable string the engine emitted on the way to the total —
    e.g.
    <em>"Visited 3 unique pages (+15)"</em>,
    <em>"Visited 2 intent pages (pricing, demo) (+30)"</em>,
    <em>"Sent / received 5 messages (+10)"</em>,
    <em>"Contact info captured (+15)"</em>. The conversation detail page
    renders the list under the Lead score section so the operator knows
    exactly which signals fired; the badge itself shows the same lines
    in its tooltip on hover. When a signal contributes 0 it is omitted —
    a cold lead with no intent matches simply lists fewer items.
</p>

<h2>Backfill existing conversations</h2>

<p>
    The score column defaults to 0 for every conversation that existed
    before this feature shipped. To bulk-recompute against history,
    run:
</p>

<pre><code>php artisan pitchbar:recompute-lead-scores</code></pre>

<p>
    Useful flags:
</p>

<ul>
    <li>
        <code>--workspace={uuid}</code> — restrict to one workspace
    </li>
    <li>
        <code>--agent={uuid}</code> — restrict to one agent (overrides
        <code>--workspace</code>)
    </li>
    <li>
        <code>--queue</code> — dispatch one
        <code>RecomputeLeadScoreJob</code> per conversation instead of
        running inline. Use this for very large workspaces (millions of
        conversations) where the analytics worker should soak up the
        work in the background.
    </li>
    <li>
        <code>--chunk=500</code> — row batch size for the
        <code>chunkById</code> iterator (default 500). Drop to 100 on
        memory-constrained hosts.
    </li>
</ul>

<p>
    The command is idempotent: re-running it just overwrites the score
    + reasons with the freshest values. Playground conversations are
    always skipped.
</p>

<h2>Zero-score conversations</h2>

<p>
    A brand-new conversation with no page view, no messages, and no
    captured contact info scores <strong>0</strong> and falls into the
    Cold bucket. The UI hides the badge entirely when the score is 0 —
    avoids a row of grey "Cold · 0" pills on every drive-by widget
    load that never triggered a turn. Conversations only start showing
    a badge after the first scoring signal fires (usually a page view
    via <code>/init</code> on a non-blank page URL).
</p>

<h2>Filtering the inbox / leads list</h2>

<p>
    Both <code>/app/inbox</code> and each agent's
    <code>/app/agents/{id}/leads</code> page expose a <strong>Hot only</strong>
    toggle in the toolbar — when on, the list is constrained to leads
    whose underlying conversation scored ≥ 70. The toggle is bookmarkable:
    the URL gets <code>?hot=1</code> and survives refresh.
</p>

<h2>Tuning</h2>

<p>
    The weights live in
    <code>app/Services/Scoring/LeadScoringEngine.php</code>. Add a new
    signal by writing a private <code>scoreFoo()</code> method that
    returns <code>['score' =&gt; int, 'reasons' =&gt; string[]]</code>
    and adding it to the <code>compute()</code> parts array. Keep the
    sum of caps reasonable — total clamps to 100 either way, but
    over-weighting a single signal makes the bucket boundaries
    meaningless.
</p>

<p>
    To extend the intent-keyword list, edit
    <code>LeadScoringEngine::INTENT_KEYWORDS</code>. Case-insensitive
    substring match against the URL only — adding a keyword does not
    re-score historical conversations until the next recompute fires
    (i.e. the next page view, message, or lead capture on that
    conversation). Use <code>php artisan pitchbar:recompute-lead-scores</code>
    to force a bulk rescore against existing rows.
</p>

<p>
    The engine caps page-view history at the most recent
    <strong>200</strong> rows per visitor
    (<code>LeadScoringEngine::MAX_PAGE_VIEWS</code>). Beyond that the
    weights saturate anyway, and the cap keeps the query cheap for
    long-lived returning visitors.
</p>
