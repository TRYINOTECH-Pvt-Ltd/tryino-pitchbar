<p>
    Pitchbar is self-hosted: <strong>your Cloudflare account is billed
    directly by Cloudflare</strong>. The Pitchbar team never sees those
    invoices and never collects a markup. This page explains exactly which
    Cloudflare resources Pitchbar uses, what each one does for your app,
    and where to read the bill.
</p>

<h2>Setup — token permissions</h2>

<p>
    Create the API token at
    <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener noreferrer" class="font-semibold underline-offset-2 hover:underline">
        My Profile → API Tokens
    </a>
    → <em>Create Token</em> → <em>Get started — Create Custom Token</em>.
    Add ALL of the following account-scoped permissions or some
    features will silently break (LLM works, Vectorize 10000s; or
    crawl works, embed fails; etc.).
</p>

<table class="mt-3 w-full text-start text-[15px]">
    <thead class="border-b text-xs font-semibold uppercase tracking-wide text-slate-500">
        <tr>
            <th class="py-2 pe-3">Permission</th>
            <th class="py-2 pe-3">Level</th>
            <th class="py-2 pe-3">What breaks if missing</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-slate-200 text-[14px]">
        <tr>
            <td class="py-2 pe-3"><code>Account → Workers AI</code></td>
            <td class="py-2 pe-3">Read</td>
            <td class="py-2 pe-3">Every chat reply + every embedding (visitor messages, indexing) — the whole RAG pipeline.</td>
        </tr>
        <tr>
            <td class="py-2 pe-3"><code>Account → Vectorize</code></td>
            <td class="py-2 pe-3">Edit</td>
            <td class="py-2 pe-3">Creates the chunks index on first run, stores/queries vectors. Without it: <code>code 10000 Authentication error</code> or <code>code 40040 Index not found</code> on every retrieval.</td>
        </tr>
        <tr>
            <td class="py-2 pe-3"><code>Account → Browser Rendering</code></td>
            <td class="py-2 pe-3">Edit</td>
            <td class="py-2 pe-3">JS-rendered crawl for sites where plain HTTP returns an empty shell (Shopify, Next.js, Vue, Angular SPAs). Without it: falls back to Browserless (if configured) or plain HTTP — many sites yield no chunks.</td>
        </tr>
        <tr>
            <td class="py-2 pe-3"><code>Account → Workers R2 Storage</code></td>
            <td class="py-2 pe-3">Edit</td>
            <td class="py-2 pe-3">Optional. Only needed when you store branding assets or uploaded source PDFs in R2 (vs. local disk / S3).</td>
        </tr>
        <tr>
            <td class="py-2 pe-3"><code>Account → Workers Scripts</code></td>
            <td class="py-2 pe-3">Edit</td>
            <td class="py-2 pe-3">Powers the "Deploy Cron Worker" button at Settings → System → Cron worker, which pushes a small Worker that ticks your queue every 60s. Without it: you'll need a cPanel cron or external uptime ping hitting <code>/api/v1/internal/queue-tick</code>.</td>
        </tr>
    </tbody>
</table>

<p class="mt-4">
    <strong>Resources scope</strong> — at the bottom of the token
    creation form, you'll see "Account Resources" and "Zone Resources."
    Set <strong>Account Resources → Include — All accounts</strong>, or
    explicitly pick the same account whose ID you paste into
    <em>Settings → System → AI providers → Cloudflare → Account ID</em>.
    Account-mismatched tokens are the #1 cause of <code>code 10000
    Authentication error</code> — the permission list looks correct but
    the token can't see your account.
</p>

<p class="mt-3">
    <strong>Verify in 10 seconds</strong> — once you have the token,
    test from any shell:
</p>

<pre class="mt-2 rounded-md bg-slate-950 px-4 py-3 text-[12px] text-emerald-300"><code>curl -H "Authorization: Bearer YOUR_TOKEN" \
     https://api.cloudflare.com/client/v4/accounts/YOUR_ACCOUNT_ID/vectorize/v2/indexes</code></pre>

<p class="mt-2">
    A 200 with a JSON list (empty or containing <code>pitchbar-chunks</code>)
    means token + account are matched. A 10000 means they aren't. A 40040
    means matched but the index doesn't exist yet — that's fine, Pitchbar
    creates it on first indexing run.
</p>

<p>
    Cloudflare is the recommended default because it bundles every primitive
    Pitchbar needs (LLM, embeddings, vector store, crawler) under one
    account with generous free tiers. If you prefer not to use Cloudflare,
    leave the keys blank in
    <a href="/settings/system" class="font-semibold underline-offset-2 hover:underline">Settings → System</a>
    and Pitchbar falls back to OpenAI or OpenRouter — see
    <a href="/documentation/env" class="font-semibold underline-offset-2 hover:underline">Environment variables</a>.
</p>

<h2>What Pitchbar uses Cloudflare for</h2>

<table class="mt-4 w-full text-start text-[15px]">
    <thead class="border-b text-xs font-semibold uppercase tracking-wide text-slate-500">
        <tr>
            <th class="py-2 pe-3">Resource</th>
            <th class="py-2 pe-3">What it does</th>
            <th class="py-2">Free tier (as of 2026)</th>
        </tr>
    </thead>
    <tbody class="divide-y text-slate-700">
        <tr>
            <td class="py-3 pe-3 align-top font-semibold">Workers AI — chat</td>
            <td class="py-3 pe-3 align-top">
                Generates every visitor reply using
                <code class="rounded bg-slate-100 px-1 font-mono text-[0.9em]">@cf/meta/llama-3.3-70b-instruct-fp8-fast</code>
                by default. One call per visitor turn.
            </td>
            <td class="py-3 align-top">
                10,000 neurons / day (≈ a few thousand short replies). Past
                that, ~$0.011 per 1k input tokens, ~$0.011 per 1k output
                tokens.
            </td>
        </tr>
        <tr>
            <td class="py-3 pe-3 align-top font-semibold">
                Workers AI — embeddings
            </td>
            <td class="py-3 pe-3 align-top">
                Turns each crawled chunk and each visitor question into a
                768-dim vector via
                <code class="rounded bg-slate-100 px-1 font-mono text-[0.9em]">@cf/baai/bge-base-en-v1.5</code>.
                One call per chunk at index time, one per visitor turn.
            </td>
            <td class="py-3 align-top">
                Counted against the same Workers AI neuron pool. Embedding
                calls are cheap — typically &lt; 1% of chat cost.
            </td>
        </tr>
        <tr>
            <td class="py-3 pe-3 align-top font-semibold">
                Workers AI — reranker
            </td>
            <td class="py-3 pe-3 align-top">
                Rescores the top-k chunks returned by Vectorize using
                <code class="rounded bg-slate-100 px-1 font-mono text-[0.9em]">@cf/baai/bge-reranker-base</code>
                so the LLM gets the most relevant grounding. One call per
                visitor turn.
            </td>
            <td class="py-3 align-top">
                Same Workers AI neuron pool. Negligible compared to chat.
            </td>
        </tr>
        <tr>
            <td class="py-3 pe-3 align-top font-semibold">Vectorize</td>
            <td class="py-3 pe-3 align-top">
                The vector database that stores your crawled content's
                embeddings and answers nearest-neighbour queries on every
                visitor turn.
            </td>
            <td class="py-3 align-top">
                30M stored dimensions + 50M queried dimensions per month
                free. A typical site (500 pages, ~5k chunks) uses ~3.8M
                stored — well within the free tier.
            </td>
        </tr>
        <tr>
            <td class="py-3 pe-3 align-top font-semibold">
                Browser Rendering
            </td>
            <td class="py-3 pe-3 align-top">
                Crawls JavaScript-heavy pages (React / Vue / Shopify
                Hydrogen) so we can index content static fetch can't see.
                Used at <em>indexing time</em>, never on the visitor hot
                path. Auto-falls-back to plain HTTP for static sites.
            </td>
            <td class="py-3 align-top">
                10 minutes / day on the free Workers plan; 10 hours / day
                on the Paid plan ($5/mo). Full re-crawls of a 500-page site
                typically take 5–15 minutes.
            </td>
        </tr>
    </tbody>
</table>

<p class="mt-4 text-sm text-slate-600">
    Free-tier numbers are Cloudflare's published values at the time of
    writing — the
    <a href="https://developers.cloudflare.com/workers-ai/platform/pricing/" target="_blank" rel="noopener noreferrer" class="font-semibold underline-offset-2 hover:underline">Workers AI pricing page</a>,
    <a href="https://developers.cloudflare.com/vectorize/platform/pricing/" target="_blank" rel="noopener noreferrer" class="font-semibold underline-offset-2 hover:underline">Vectorize pricing page</a>,
    and
    <a href="https://developers.cloudflare.com/browser-rendering/platform/pricing/" target="_blank" rel="noopener noreferrer" class="font-semibold underline-offset-2 hover:underline">Browser Rendering pricing page</a>
    are the source of truth — Cloudflare updates them periodically.
</p>

<h2>Realistic monthly cost ranges</h2>

<p>
    These are working estimates, not commitments. Actual cost depends on
    visitor volume, average reply length, and how often you re-crawl.
</p>

<ul class="mt-2 list-disc space-y-2 ps-6 text-[15px] leading-7 text-slate-700">
    <li>
        <strong>Hobby site (≤ 500 visitors / month, &lt; 100 pages
        indexed)</strong> — almost certainly $0 / month. Everything fits
        in the free tiers.
    </li>
    <li>
        <strong>SMB site (5,000 visitors / month, 500 pages indexed,
        weekly re-crawl)</strong> — typically $0–$5 / month. The Browser
        Rendering Paid plan ($5/mo flat) is the most likely line item if
        your site is JS-heavy.
    </li>
    <li>
        <strong>Mid-market (50,000 visitors / month, 5,000 pages indexed,
        daily re-crawl)</strong> — typically $20–$60 / month, dominated
        by Workers AI chat tokens.
    </li>
</ul>

<h2>Where to see the bill</h2>

<p>
    Cloudflare bills directly through their dashboard:
</p>

<ol class="mt-2 list-decimal space-y-1 ps-6 text-[15px] leading-7 text-slate-700">
    <li>
        Sign in at
        <a href="https://dash.cloudflare.com" target="_blank" rel="noopener noreferrer" class="font-semibold underline-offset-2 hover:underline">dash.cloudflare.com</a>
        with the same account whose Account ID you pasted into Pitchbar.
    </li>
    <li>
        Open <strong>Manage Account → Billing</strong> for invoices, or
        <strong>AI → Workers AI → Analytics</strong> /
        <strong>AI → Vectorize → Analytics</strong> for live usage
        graphs by model.
    </li>
    <li>
        For day-by-day Workers AI cost, the Workers AI dashboard shows a
        \"neurons used\" graph that maps directly to billed usage.
    </li>
</ol>

<h2>Capping spend</h2>

<p>
    Two safety levers ship in Pitchbar:
</p>

<ul class="mt-2 list-disc space-y-2 ps-6 text-[15px] leading-7 text-slate-700">
    <li>
        <strong>Per-plan AI controls.</strong> The
        <a href="/admin/plans" class="font-semibold underline-offset-2 hover:underline">platform admin's Plans page</a>
        sets a per-message <code class="rounded bg-slate-100 px-1 font-mono text-[0.9em]">max_tokens</code>
        and a monthly message ceiling. Hitting the cap surfaces an
        upgrade prompt to the visitor instead of burning more tokens.
    </li>
    <li>
        <strong>Cloudflare-side spending limit.</strong> In
        <a href="https://dash.cloudflare.com" target="_blank" rel="noopener noreferrer" class="font-semibold underline-offset-2 hover:underline">dash.cloudflare.com → Billing → Notifications</a>
        you can set a budget alert and have Cloudflare email you when
        usage crosses a threshold.
    </li>
</ul>

<h2>Switching providers</h2>

<p>
    If you'd rather not use Cloudflare at all:
</p>

<ul class="mt-2 list-disc space-y-2 ps-6 text-[15px] leading-7 text-slate-700">
    <li>
        Leave the Cloudflare Account ID and API token blank in
        Settings → System and set
        <code class="rounded bg-slate-100 px-1 font-mono text-[0.9em]">OPENAI_API_KEY</code>
        — Pitchbar's provider chain (Cloudflare → OpenRouter → OpenAI)
        falls through to OpenAI automatically.
    </li>
    <li>
        OpenAI's chat models are typically more expensive per turn than
        Workers AI but ship industry-standard quality on
        <code class="rounded bg-slate-100 px-1 font-mono text-[0.9em]">gpt-4o-mini</code>
        and similar.
    </li>
    <li>
        Vector storage in this mode falls back to a Qdrant instance you
        host yourself — see
        <a href="/documentation/env" class="font-semibold underline-offset-2 hover:underline">Environment variables</a>.
    </li>
</ul>

<p class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-relaxed text-amber-900">
    <strong>Bottom line.</strong> Cloudflare is the cheapest and simplest
    way to run Pitchbar. Most installs stay within the free tiers; even
    high-traffic ones rarely cross $50/month. You always have full
    visibility — the bill lives in your Cloudflare dashboard, not in
    Pitchbar.
</p>
