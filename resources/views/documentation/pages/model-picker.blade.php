<p>
    The chat model your agent uses is the single biggest knob for reply
    speed. A 200ms time-to-first-token (TTFT) feels instant; a 1.5s TTFT
    feels broken. Most "the bot is slow" complaints from buyers trace
    back to picking a heavyweight model when a fast one would have done.
</p>

<h2>Where to pick a model</h2>

<p>
    <strong>Super-admin → Settings → System → AI providers.</strong>
    Each provider (Cloudflare, OpenAI, OpenRouter) ships a dropdown of
    curated models with a speed badge, a cost tier, and an estimated
    TTFT. The estimate comes from each vendor's published latency
    dashboard as of the catalogue's last update; the real number on
    your install can vary by ±30% depending on your region.
</p>

<h2>The "Test connection" button</h2>

<p>
    Next to each model dropdown is a <strong>Test connection</strong>
    button. Click it and the server runs a one-token chat against the
    configured provider and reports the actual TTFT your install sees.
    The measurement is cached for 24 hours so reloading the page does
    not burn API budget. Click again to refresh.
</p>

<p>
    If the probe fails — bad key, model deprecated, provider down — the
    button shows the error inline so you can fix it without leaving the
    page.
</p>

<h2>Default picks (June 2026)</h2>

<table>
    <thead><tr><th>Provider</th><th>Pick</th><th>Why</th></tr></thead>
    <tbody>
        <tr>
            <td>Cloudflare Workers AI</td>
            <td><code>@cf/meta/llama-3.3-70b-instruct-fp8-fast</code></td>
            <td>Fastest 70B on Cloudflare. Free for most installs. Tool-calling reliable.</td>
        </tr>
        <tr>
            <td>OpenAI</td>
            <td><code>gpt-4o-mini</code></td>
            <td>10× cheaper than GPT-4o, ~3× faster TTFT. Quality good enough for sales-bot use.</td>
        </tr>
        <tr>
            <td>OpenRouter</td>
            <td><code>meta-llama/llama-3.3-70b-instruct:free</code></td>
            <td>Free tier; rate-limited but enough for low-volume sites.</td>
        </tr>
    </tbody>
</table>

<h2>When to pick something slower</h2>

<ul>
    <li>
        <strong>Long-form / nuanced replies (legal, finance, support
        escalations).</strong> Switch to GPT-4o or Claude 3.5 Sonnet.
        Buyers will accept slower TTFT in exchange for fewer wrong
        answers.
    </li>
    <li>
        <strong>Huge knowledge bases.</strong> Gemini Flash 1.5 has a
        1M-token context window. Fits anything in one prompt.
    </li>
    <li>
        <strong>EU residency requirement.</strong> Mistral Small via
        OpenRouter routes to European infrastructure.
    </li>
</ul>

<h2>Automatic model fallback (self-heal)</h2>

<p>
    A single-Cloudflare install no longer dies when its chat model is
    slow, cold-starting, or returns a 5xx. The app keeps a
    <strong>second Cloudflare chat model</strong> on standby and switches
    to it automatically for that turn — no second provider key required.
    By default the primary is the 70B model and the fallback is the fast
    8B model (<code>@cf/meta/llama-3.1-8b-instruct</code>), so a slow
    heavyweight degrades to a quick lighter answer instead of an error.
</p>

<ul>
    <li>
        <strong>Zero overhead when healthy.</strong> The fallback is only
        tried after the primary actually fails — a working primary pays
        nothing, and on the visitor hot path the switch can only happen
        <em>before</em> the first token, so the streaming latency budget
        is untouched.
    </li>
    <li>
        <strong>Cloudflare first, then other providers.</strong> If you
        also have an OpenAI or OpenRouter key, the order is
        Cloudflare&nbsp;primary → Cloudflare&nbsp;fallback →
        OpenAI&nbsp;/&nbsp;OpenRouter. We exhaust Cloudflare's own models
        before hopping providers.
    </li>
    <li>
        <strong>Override or disable it.</strong> Set
        <code>CLOUDFLARE_CHAT_MODEL_FALLBACK</code> to any other Workers
        AI chat slug, or to an empty value to turn the in-provider
        fallback off. A fallback equal to the primary is ignored (a
        same-model retry buys nothing).
    </li>
</ul>

<p>
    Each switch is recorded as a <em>provider failover</em> event in the
    <a href="/documentation/widget-monitor">Widget Monitor</a>, so a
    primary model that fails over constantly is visible — that's your
    signal to make the fallback the primary, or to size up the account.
</p>

<h2>Why a model is or is not in the catalogue</h2>

<p>
    The catalogue (<code>App\Services\Llm\ModelCatalog</code>) is
    hand-curated. We surface models that:
</p>

<ul>
    <li>Stream tokens via the OpenAI-style <code>chat/completions</code> endpoint.</li>
    <li>Reliably honour the JSON tool-calling format (where flagged).</li>
    <li>Are not deprecated by their vendor.</li>
</ul>

<p>
    If your model is not in the dropdown, the picker still lets you
    paste a custom ID — the "Custom" entry pins at the top and falls
    back to the original free-text input. The Test connection button
    works against custom models too.
</p>

<h2>Caveats</h2>

<ul>
    <li>
        <strong>Catalogue TTFT estimates drift.</strong> Vendors silently
        change inference hardware. Re-run Test connection monthly if
        you care about exact numbers.
    </li>
    <li>
        <strong>Probe runs in your local timezone / region.</strong> A
        buyer in Tokyo will see different latency than one in Frankfurt
        for the same model. The measurement reflects whichever server
        the install runs on.
    </li>
    <li>
        <strong>Probe consumes one token per click.</strong> At
        OpenAI's gpt-4o-mini rate, that is well under
        $0.0001. Negligible.
    </li>
</ul>
