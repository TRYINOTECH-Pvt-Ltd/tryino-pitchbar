<p>
    Pitchbar is a sales-AI chat widget you embed on any website. A visitor opens
    your site, the widget loads, and an agent grounded in <em>your own
    knowledge base</em> answers their questions, captures leads, and hands off to
    a human when needed. This documentation covers how to build agents,
    embed them, run your workspace, and operate the platform.
</p>

<div class="callout callout-info">
    <svg class="callout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <div class="callout-body">
        <div class="callout-title">New here?</div>
        Jump to the <a href="/documentation/quickstart">5-minute Quickstart</a>, or
        skim <a href="/documentation/concepts">Core concepts</a> to get the
        mental model first.
    </div>
</div>

<h2>What you can do</h2>

<div class="docs-cards">
    <a class="docs-card" href="/documentation/agents">
        <div class="docs-card-title">Build an agent</div>
        <div class="docs-card-body">Persona, tone, language, system prompt, confidence threshold — all configurable per agent.</div>
    </a>
    <a class="docs-card" href="/documentation/knowledge">
        <div class="docs-card-title">Train it on your data</div>
        <div class="docs-card-body">Crawl URLs and sitemaps, paste text, sync from Notion or Google Docs. Auto-index pages visitors land on.</div>
    </a>
    <a class="docs-card" href="/documentation/embed">
        <div class="docs-card-title">Embed in one line</div>
        <div class="docs-card-body">A single &lt;script&gt; tag drops the widget on any page. ≤50KB gzipped, isolated in a Shadow DOM.</div>
    </a>
    <a class="docs-card" href="/documentation/inbox">
        <div class="docs-card-title">Take over conversations</div>
        <div class="docs-card-body">Real-time inbox lets a human jump in mid-thread. Visitor sees the handoff seamlessly.</div>
    </a>
    <a class="docs-card" href="/documentation/analytics">
        <div class="docs-card-title">Watch the metrics</div>
        <div class="docs-card-body">Conversations, low-confidence gaps, lead conversion, deflection rate — all in one dashboard.</div>
    </a>
    <a class="docs-card" href="/documentation/billing">
        <div class="docs-card-title">Plans &amp; quotas</div>
        <div class="docs-card-body">Stripe-backed subscriptions, monthly conversation quotas, branding removal on paid plans.</div>
    </a>
</div>

<h2>How it fits together</h2>

<p>
    The product has three surfaces:
</p>

<ul>
    <li><strong>Customer app</strong> — the React/Inertia SPA you use to build agents, manage knowledge, run the inbox, and handle billing. Lives at <code>/dashboard</code>, <code>/app/agents</code>, <code>/app/inbox</code>, etc.</li>
    <li><strong>Visitor widget</strong> — the Preact bundle that runs on your customers' sites. Streams answers from the same backend over a signed JWT.</li>
    <li><strong>Platform admin</strong> — the operator-only console at <code>/admin</code> for plans, subscriptions, usage, queue health, and global notifications. Only super-admins see it.</li>
</ul>

<p>
    Under the hood: Laravel 13 + Octane, Postgres + Redis, Laravel Reverb for
    realtime, Stripe via Cashier, Cloudflare Workers AI / Vectorize / Browser
    Rendering for the AI stack (with OpenAI + Qdrant as fallback). The full
    stack is documented under
    <a href="/documentation/architecture">Architecture</a>.
</p>

<h2>Reading order</h2>

<p>
    The sidebar is grouped roughly in the order you'll need things — start
    from the top, skip what doesn't apply. If you're an operator running the
    platform, jump straight to <a href="/documentation/admin-overview">Platform admin</a>
    and <a href="/documentation/observability">Observability</a>.
</p>
