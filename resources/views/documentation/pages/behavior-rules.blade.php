<p>
    Behavior rules let the agent <em>do something</em> beyond answering — pop a
    CTA when the visitor matches an intent, prompt for an email after a
    threshold, suggest a curated answer when keywords match. They run on
    every turn, both server-side (during prompt assembly) and client-side
    (for visible UI like CTAs).
</p>

<h2>Anatomy of a rule</h2>

<p>
    Each rule is a row in <code>behavior_rules</code> with three pieces:
</p>

<ul>
    <li><strong>kind</strong> — what type of rule. Currently: <code>cta</code>, <code>lead_capture</code>, <code>route</code>, <code>curated</code>.</li>
    <li><strong>conditions</strong> — JSON describing when the rule fires (intent keywords, page URL match, scroll depth, idle time, message count).</li>
    <li><strong>action</strong> — JSON describing what to do (show CTA card, ask for email, route to human, return curated text).</li>
</ul>

<p>
    Rules also have <code>enabled</code> (boolean) and <code>priority</code>
    (integer) fields. Higher priority wins when multiple rules match.
    Disabled rules are kept around for history, not deleted.
</p>

<h2>Editing rules</h2>

<p>
    Open <code>/app/agents/{id}/behavior</code>. The form is a kind-aware
    builder — pick the kind, fill in conditions and action, save. Up to 20
    enabled rules are loaded into the widget at init time; anything beyond
    that is ignored at runtime (so prune the disabled list periodically).
</p>

<h2>Triggers (visitor-side)</h2>

<p>
    The widget tracks lightweight signals to fire rules without the visitor
    saying anything:
</p>

<ul>
    <li><strong>Scroll depth</strong> — fired when the visitor passes a percentage of the page.</li>
    <li><strong>Idle</strong> — fired when there's no input or scroll for N seconds.</li>
    <li><strong>Exit intent</strong> — fired when the cursor leaves the viewport toward the top of the screen.</li>
    <li><strong>URL match</strong> — fired when the current page URL matches a regex.</li>
</ul>

<p>
    These are evaluated locally in the widget so the trigger fires
    instantly. The actual action (show a CTA card, etc.) happens client-side
    too — no round-trip needed.
</p>

<h2>CTAs</h2>

<p>
    A CTA action renders a card inside the chat panel with a title,
    description, and one or two buttons. Buttons can:
</p>

<ul>
    <li><strong>Open a URL</strong> in a new tab.</li>
    <li><strong>Send a message</strong> as if the visitor typed it.</li>
    <li><strong>Capture a lead</strong> (open the inline lead form).</li>
    <li><strong>Dismiss</strong>.</li>
</ul>

<p>
    Manage CTAs at <code>/app/agents/{id}/ctas</code>. They're stored as
    behavior rules with <code>kind=cta</code>, but the dedicated UI is
    friendlier than the raw rule editor.
</p>

<h2>Curated answers</h2>

<p>
    Curated answers short-circuit the RAG pipeline. If a visitor's question
    matches a curated trigger (substring or regex), the curated text streams
    back instead of going through retrieval and the LLM. Useful for:
</p>

<ul>
    <li>Pricing questions where you want exact numbers, never paraphrased.</li>
    <li>Refund/legal language that has to be word-for-word.</li>
    <li>"How do I contact support?" where you want to control the routing.</li>
</ul>

<p>
    Manage them at <code>/app/agents/{id}/curated</code>. Each entry has a
    list of trigger phrases, the canned answer, and an optional citation. At
    runtime the agent streams the curated text token-by-token to mimic the
    LLM's behavior — visitors don't see a jarring pop-in.
</p>

<h2>Lead capture rules</h2>

<p>
    A <code>lead_capture</code> rule fires the inline lead form. Common
    triggers:
</p>

<ul>
    <li>After N message turns (visitor's intent looks real).</li>
    <li>When low-confidence is detected ("we'll follow up").</li>
    <li>On exit intent ("before you go…").</li>
</ul>

<p>
    The form fields are configurable — name, email, phone, and any custom
    field you've defined. See <a href="/documentation/widget-features">Voice,
    leads &amp; persistence</a> for the visitor-side flow.
</p>

<h2>Routing rules</h2>

<p>
    A <code>route</code> rule pings a human operator. Use it to escalate
    when:
</p>

<ul>
    <li>The visitor explicitly asks for a human.</li>
    <li>Confidence drops below a threshold.</li>
    <li>The conversation reaches a complexity heuristic (long messages, multiple unanswered topics).</li>
</ul>

<p>
    The route appears in <code>/app/inbox</code> as an unread thread; an
    operator can claim it and continue inline. See
    <a href="/documentation/inbox">Inbox &amp; human takeover</a>.
</p>

<h2>Experiments</h2>

<p>
    Behavior rules can be A/B tested. The Experiments page
    (<code>/app/agents/{id}/experiments</code>) lets you split traffic
    between two rule variants and watch the conversion delta. The split is
    per-visitor, not per-conversation — once a visitor is bucketed they stay
    in that bucket for the lifetime of the conversation.
</p>
