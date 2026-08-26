<p>
    Every workspace admin gets a self-serve diagnostic at
    <code>/app/system-health</code>. The page is read-only — it does
    not call any external API and cannot mutate state — and it surfaces
    the four config gaps that account for ~90% of "the bot isn't
    working" support tickets:
</p>

<ol>
    <li>
        <strong>LLM provider</strong> — resolves the bound
        <code>OpenAiClient</code>. Green when Workers AI / OpenAI /
        OpenRouter is configured. Red when the deterministic
        <code>FakeOpenAi</code> is in play (no real provider).
    </li>
    <li>
        <strong>Cloudflare creds</strong> — checks
        <code>CLOUDFLARE_ACCOUNT_ID</code> +
        <code>CLOUDFLARE_API_TOKEN</code>. Amber when only one is
        present; green when both are.
    </li>
    <li>
        <strong>Embedding fallback</strong> — checks
        <code>OPENAI_API_KEY</code>. Amber when missing: the install
        works on the happy path but the next Workers AI rate-limit
        will halt indexing.
    </li>
    <li>
        <strong>Queue worker</strong> — surfaces the queue driver,
        pending count, and failed-job count. Amber when queue is
        <code>sync</code> (fine for dev, broken for prod where SSE +
        crawl jobs need their own workers).
    </li>
</ol>

<h2>Source-level problems</h2>

<p>
    Beneath the four cards is a list of every <code>Source</code> in
    the workspace whose status is <code>failed</code> or stuck on
    <code>crawling</code>, ordered by most recently updated.
    <code>IndexDocumentJob</code> stamps the exception class name into
    each row's <code>error</code> column, so the admin can tell a
    rate-limit (<code>[OpenAiRateLimitException]</code>) apart from a
    bad-request (<code>[OpenAiBadRequestException]</code>) apart from a
    Cloudflare 40040 (Vectorize provisioning lag).
</p>

<h2>Cloudflare Workers AI: non-Llama models</h2>

<p>
    Workers AI's <code>/v1/chat/completions</code> endpoint is OpenAI-
    compatible <em>in name</em>, but model families diverge in two ways
    that affect the bot:
</p>

<ul>
    <li>
        <strong>Streaming shape varies.</strong> Llama 3.3 emits the
        OpenAI SSE shape (<code>data: {"choices":[{"delta":{"content":"..."}}]}</code>).
        Other families (Mistral, some Gemma builds, Qwen variants) emit
        either NDJSON without the <code>data:</code> prefix or a CF
        native <code>{"response":"..."}</code> shape on the same endpoint.
        <code>WorkersAiClient::streamChat()</code> handles all three
        (since 2026-05-29) so any chat-capable Workers AI model
        streams tokens.
    </li>
    <li>
        <strong>Not every Workers AI model has a chat endpoint.</strong>
        Embedding / classification / Whisper models silently return
        empty bodies on <code>/v1/chat/completions</code>. When the
        Settings → System chat probe surfaces
        <em>"No content via streaming OR non-streaming"</em>, the
        configured <code>CLOUDFLARE_CHAT_MODEL</code> isn't a chat
        model — switch to any <code>@cf/meta/llama-*</code> slug or a
        Mistral instruct variant.
    </li>
</ul>

<p>
    The chat probe runs streaming first; if streaming yields zero
    tokens it retries non-streaming. Three outcomes:
</p>

<ul>
    <li><strong>Both work</strong> → green, surfaces the model's reply.</li>
    <li><strong>Streaming empty, non-streaming worked</strong> → red, points
        the operator at a streaming-capable slug. The bot still works in
        non-streaming fallback paths, but the visitor SSE path needs a
        streaming-capable model to be useful.</li>
    <li><strong>Both empty</strong> → red, the configured model has no
        OpenAI-compat chat endpoint.</li>
</ul>

<h2>Timeouts vs. network failures</h2>

<p>
    When a turn fails to reach the model, the error you see (in the
    Playground bubble and the Settings → System probes) now distinguishes
    <em>four</em> transport failures instead of lumping them all under
    "check your firewall/DNS". The distinction matters: three of them are
    network-config problems on your server, but the most common one —
    a <strong>timeout</strong> — is not.
</p>

<ul>
    <li>
        <strong>Timed out (slow provider).</strong> <em>"The AI provider
        accepted the request but did not respond in time…"</em> The
        connection succeeded; the model was just too slow — almost always
        a large model (e.g. Llama 3.3 70B) cold-starting or under load
        overrunning the per-call timeout. This is <strong>not</strong> a
        firewall or DNS fault. On Cloudflare the app already
        <a href="/documentation/model-picker">auto-switches to a second
        Cloudflare model</a> when the primary times out, so most cold
        starts self-heal without anyone noticing. If you still see this,
        the fallback model is timing out too — switch
        <code>CLOUDFLARE_CHAT_MODEL</code> (and/or
        <code>CLOUDFLARE_CHAT_MODEL_FALLBACK</code>) to faster variants,
        or add another provider for an extra failover hop.
    </li>
    <li>
        <strong>DNS lookup failed.</strong> <em>"Could not resolve the AI
        provider's hostname…"</em> The server can't resolve the provider
        domain — check the host's DNS resolver and outbound access.
    </li>
    <li>
        <strong>Connection refused / blocked.</strong> <em>"Could not
        open a connection to the AI provider…"</em> DNS resolved but the
        connection was refused or dropped — an outbound firewall or a
        blocked port. This is the genuine "check your firewall" case.
    </li>
    <li>
        <strong>Other transport error.</strong> A TLS handshake failure,
        empty reply, or receive failure — generic outbound-network line.
    </li>
</ul>

<p>
    A single-provider install (the default) has no failover to mask a
    slow primary, so a cold-start timeout surfaces directly to whoever's
    in the Playground. Adding a second provider key is the durable fix;
    a faster model is the quick one.
</p>

<h2>Access control</h2>

<p>
    Workspace owners + admins can load the page; viewers get 403. The
    sidebar entry under "Run your workspace" only appears for users
    who can see the route.
</p>
