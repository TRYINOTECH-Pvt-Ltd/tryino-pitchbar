<p>
    The widget is more than a chat box. It captures leads inline, transcribes
    voice, persists across reloads, and shows a live "human is here" state
    when an operator takes over. This page covers each feature and how to
    configure it.
</p>

<h2>Conversation persistence</h2>

<p>
    Every visitor gets an <code>anon_id</code> (a random string written to
    <code>localStorage</code> on first visit). On reload, the widget calls
    <code>/v1/widget/init</code> with the same <code>anon_id</code> and the
    server resumes the most recent conversation if it's less than 24 hours
    old.
</p>

<p>
    The init response includes the last 30 messages so the chat log
    rehydrates in the order the visitor left it. The "Clear conversation"
    button (in the widget header) writes a <code>cleared_at</code> timestamp
    on the conversation row — past messages stay in the database for
    analytics + lead linkage but stop being shown to the visitor. It also
    forgets the server-side LLM history cache, so the next turn truly
    starts a fresh context instead of silently continuing the cleared
    conversation.
</p>

<h2>Voice mic</h2>

<p>
    A microphone button in the input area lets visitors dictate instead of
    type. The widget uses the browser's built-in
    <code>SpeechRecognition</code> API — no server-side speech model. While
    recording the mic shows a sonar-ring animation; clicking again stops
    and inserts the transcript into the input box (appended if there's
    already text there, so you can dictate, edit, then dictate more).
</p>

<p>
    Browsers without <code>SpeechRecognition</code> (Firefox, older Safari)
    don't show the mic button. There's no fallback — the feature is
    opportunistic.
</p>

<h2>Typing indicator stages</h2>

<p>
    The pending bubble narrates what the server is doing instead of a
    static "Thinking…": <em>Searching your site…</em> during retrieval,
    <em>Thinking…</em> while the LLM composes, and a tool-specific label
    when a tool runs — <em>Checking your order…</em>,
    <em>Creating your ticket…</em>, <em>Finding the right article…</em>,
    <em>Connecting you with a human…</em> (unknown tools show
    <em>Working on it…</em>). Labels clear the moment the first token
    streams in.
</p>

<h2>Stream resilience</h2>

<p>
    If the SSE connection drops mid-turn without a <code>done</code> event
    (typically a proxy's <code>proxy_read_timeout</code> killing an idle
    stream during a slow model turn), the widget treats it as a failed
    attempt: it retries transparently up to three times and, if every
    attempt dies, shows a retry pill — the bubble never sits on
    "Thinking…" forever.
</p>

<h2>Lead capture</h2>

<p>
    The widget can collect contact info inline without forcing the visitor
    away from the conversation. Lead capture fires when:
</p>

<ul>
    <li>A behavior rule of <code>kind=lead_capture</code> matches.</li>
    <li>The visitor explicitly asks to be contacted ("can someone call me?", "email me a quote").</li>
    <li>You wire a CTA button to the <code>lead_capture</code> action.</li>
</ul>

<p>
    The form fields are configured per agent. The default is name + email;
    you can add phone, company, and custom fields. Submitted leads are
    POSTed to <code>/v1/widget/leads</code> (rate-limited per JWT) and
    appear immediately in <code>/app/inbox</code> (the per-workspace lead list).
    The "Thanks — we'll get back to you soon" confirmation auto-dismisses
    after 5 seconds and has an explicit × for visitors who want it gone
    sooner.
</p>

<h3>Smart capture from intent</h3>

<p>
    The latest update (commit <code>9190aa5</code>) adds intent-based capture:
    the widget watches the conversation for phrases that suggest a real
    sales intent — pricing questions, "is this right for…", "can I demo…" —
    and offers the lead form proactively after a few turns. Threshold and
    phrase list are tunable per agent.
</p>

<h2>Human takeover</h2>

<p>
    When a workspace operator claims a conversation in <code>/app/inbox</code>,
    the widget receives a Reverb event (<code>conversation.takeover</code>)
    and updates the chat header to show a "Human is here" badge. From that
    point, the AI stays paused — every visitor message goes to the operator,
    every operator reply streams to the visitor. The visitor sees one
    continuous thread; under the hood the message <code>role</code> flips
    from <code>assistant</code> to <code>human-agent</code> and back.
</p>

<p>
    See <a href="/documentation/inbox">Inbox &amp; human takeover</a> for the
    operator side.
</p>

<h2>Citations</h2>

<p>
    Whenever the agent answers from retrieved sources, citation chips appear
    below the message. Click one to open the source URL in a new tab. The
    chips are numbered (<code>[1]</code>, <code>[2]</code>) matching inline
    references in the response text — visitors who care can verify the
    answer; visitors who don't see a clean reply.
</p>

<p>
    Curated answers can include an optional citation URL too — useful when
    the canned answer is sourced from a specific page.
</p>

<h2>Streaming</h2>

<p>
    Messages stream token-by-token over Server-Sent Events. The widget
    reads the stream and appends tokens to the DOM in real time. If the
    stream errors mid-flight (network blip, LLM timeout), the widget
    auto-retries up to 3 times before showing an error state — and only
    one user bubble appears even on retries (commit <code>a576e1c</code>).
</p>

<h2>Branding</h2>

<p>
    The widget footer shows a "Powered by Pitchbar" link by default. It's
    hidden for workspaces on a plan with the <code>remove_branding</code>
    feature flag enabled — see <a href="/documentation/billing">Billing &amp;
    plans</a>.
</p>

<p>
    The brand label, URL, and logo all come from platform-admin
    configuration (<code>config('branding.*')</code> + the optional
    <code>app_settings</code> singleton overrides), so a self-hosted
    deployment can rebrand the footer entirely.
</p>

<h2>Storage</h2>

<p>
    The widget uses <code>localStorage</code> for:
</p>

<ul>
    <li><code>anon_id</code> — persistent visitor identifier.</li>
    <li>Conversation cleared-state (which message IDs the visitor has hidden via "Clear").</li>
</ul>

<p>
    No personally identifiable data is stored client-side. The JWT itself
    lives in memory — it's re-issued on every init.
</p>
