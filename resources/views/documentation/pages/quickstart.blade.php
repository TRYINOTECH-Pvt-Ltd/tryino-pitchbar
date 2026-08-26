<p>
    From signup to a live agent answering questions on your site in under
    five minutes. Each step is sized so you can stop and pick it up later.
</p>

<h2>1. Create an account</h2>

<p>
    Visit <a href="/">the home page</a> and click <strong>Get started</strong>. Pitchbar uses
    Laravel Fortify for auth, so you'll get standard email + password
    registration, plus optional 2FA in your account settings later.
</p>

<p>
    On signup we auto-create your first <strong>workspace</strong> and a draft
    agent named after your company domain. If you entered a website URL during
    signup, that domain is also added to the agent's allowed origins so the
    embed works without extra setup.
</p>

<h2>2. Run the onboarding wizard</h2>

<p>
    Land on <code>/onboarding</code>. The wizard walks you through:
</p>

<ol>
    <li><strong>Confirm your website URL.</strong> The agent will learn from this site.</li>
    <li><strong>Auto-discover crawlable pages.</strong> We probe your sitemap and a handful of common paths (<code>/about</code>, <code>/pricing</code>, <code>/faq</code>, <code>/docs</code>, etc.).</li>
    <li><strong>Pick which pages to ingest.</strong> Each page becomes a knowledge source — text gets chunked and embedded into the vector store.</li>
    <li><strong>Watch them flip to "indexed".</strong> Polls every few seconds. Most sites finish in under a minute.</li>
    <li><strong>Copy the embed snippet.</strong> One line of HTML.</li>
</ol>

<h2>3. Embed the widget</h2>

<p>
    Paste this just before <code>&lt;/body&gt;</code> on any page where you want
    the agent to appear. Replace <code>YOUR_AGENT_ID</code> with the value the
    onboarding wizard shows you (also visible on every Agent's
    <em>Settings</em> page).
</p>

<pre><code>&lt;script
    src="https://your-app.test/widget/widget.js"
    data-agent-id="YOUR_AGENT_ID"
    async&gt;&lt;/script&gt;</code></pre>

<div class="callout callout-warning">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div class="callout-body">
        <div class="callout-title">Allowed origins matter</div>
        The widget only loads on origins listed in the agent's <code>allowed_origins</code>.
        Empty list means deny-everywhere. See <a href="/documentation/allowed-origins">Allowed origins</a> for the strict-match rules.
    </div>
</div>

<h2>4. Publish the agent</h2>

<p>
    The widget also loads in <em>draft</em> mode locally so you can test, but
    it won't answer real visitors until the agent is <strong>published</strong>.
    From the agent's overview page, click <strong>Publish</strong> — this snapshots
    the current persona, prompt, theme, and behavior rules into a versioned
    <code>agent_version</code> row that the runtime reads.
</p>

<h2>5. Open the inbox</h2>

<p>
    Head to <code>/app/inbox</code>. As soon as your first visitor sends a message,
    you'll see it appear live. From there you can read transcripts, jump in
    as a human operator, and capture leads.
</p>

<h2>What's next?</h2>

<div class="docs-cards">
    <a class="docs-card" href="/documentation/customize">
        <div class="docs-card-title">Tune the persona</div>
        <div class="docs-card-body">System prompt, tone, starter prompts, theme.</div>
    </a>
    <a class="docs-card" href="/documentation/behavior-rules">
        <div class="docs-card-title">Add behavior rules</div>
        <div class="docs-card-body">Trigger CTAs based on what visitors ask or do.</div>
    </a>
    <a class="docs-card" href="/documentation/billing">
        <div class="docs-card-title">Pick a plan</div>
        <div class="docs-card-body">The free plan caps monthly conversations — upgrade once you outgrow it.</div>
    </a>
</div>
