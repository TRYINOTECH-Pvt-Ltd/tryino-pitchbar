<p>
    A/B experiments let you trial different agent <em>personas</em>
    against each other on real visitor traffic and pick the winner
    based on engagement / lead-capture / conversation length. Pitchbar
    assigns each visitor stickily — the same person always sees the
    same variant — so the comparison is honest.
</p>

<h2>Where it lives</h2>

<p>
    Open any agent → <strong>A/B experiments</strong> tab in the agent
    nav (Beaker icon). URL: <code>/app/agents/{id}/experiments</code>.
    Owners and Admins can create / start / stop experiments; Editors
    can't.
</p>

<h2>Creating an experiment</h2>

<ol>
    <li>Click <strong>New experiment</strong>.</li>
    <li>Pick a <strong>kind</strong>:
        <ul>
            <li><strong>persona</strong> — variants override the agent's name + tone in the system prompt for assigned visitors. Use this to test "Aria" vs "Max", "friendly" vs "punchy", etc.</li>
            <li><strong>cta</strong> — variants record assignment but don't yet alter the runtime CTA payload. Recorded for measurement; full runtime application ships in a future release.</li>
            <li><strong>trigger</strong> — same as cta — measurement only.</li>
        </ul>
    </li>
    <li>Add at least 2 variants. Default is <code>control</code> + <code>treatment</code> at 50/50. You can change weights (any positive integers — they're normalised) and names.</li>
    <li>For <code>persona</code> kind, each variant's <code>config</code> JSON should hold a <code>persona</code> object:
        <pre><code>{ "persona": { "name": "Aria", "tone": "warm and concise" } }</code></pre>
        The widget renders the variant's persona <code>name</code> in the chat header, and the LLM speaks under that name + tone for the assigned conversation.
    </li>
    <li>Save. Status starts at <code>draft</code> — no visitors are assigned yet.</li>
    <li>Click <strong>Start</strong>. Status flips to <code>running</code>. Every subsequent first-turn visitor is bucketed.</li>
</ol>

<h2>How assignment works</h2>

<p>
    On the first message of a conversation, the
    <code>MessageStreamController</code> calls
    <code>ExperimentResolver::resolveForConversation</code>:
</p>

<ol>
    <li>If <code>conversation.variant_id</code> is already set, use it (sticky).</li>
    <li>Otherwise, look up the most recently started <code>running</code> experiment for this agent. Only ONE active experiment per agent — if you start a second one while the first is running, the resolver picks the most-recent. To run a different kind, stop the previous one first.</li>
    <li>Hash <code>(visitor_id + experiment_id)</code> into a bucket on the weighted variant list (<code>Assigner</code>). The same visitor returning days later lands in the same variant — the assignment row is durable.</li>
    <li>Persist <code>conversation.variant_id</code>. Every future turn for this conversation reads the same variant.</li>
    <li>For <code>kind = persona</code>, the variant's <code>config[persona]</code> overrides the agent's default persona in <code>PromptBuilder::build</code> for that turn.</li>
</ol>

<h2>Seeing it in action</h2>

<p>
    The fastest way to confirm the wiring:
</p>

<ol>
    <li>Create a <code>persona</code> experiment with two clearly different variants — e.g. <code>{ "persona": { "name": "Helpfulbot" } }</code> vs <code>{ "persona": { "name": "Snarkbot" } }</code>.</li>
    <li>Start the experiment.</li>
    <li>Open your widget in two different browsers (or one normal + one incognito — different cookies = different <code>visitor_id</code>).</li>
    <li>Ask the same question in each. The chat panel header should read "Helpfulbot" in one and "Snarkbot" in the other, and the answers should sound noticeably different.</li>
    <li>Open <code>/admin/conversations</code> and confirm each conversation row has a <code>variant_id</code> stamped.</li>
</ol>

<h2>Measuring results</h2>

<p>
    All persisted: <code>experiment_assignments</code> rows + the
    <code>variant_id</code> on every <code>conversations</code> row.
    Join those two tables against <code>messages</code> and
    <code>leads</code> for any analysis you want — e.g.:
</p>

<pre><code>SELECT v.name,
       COUNT(DISTINCT c.id) AS conversations,
       COUNT(DISTINCT l.id) AS leads_captured,
       AVG(c.message_count) AS avg_messages
FROM variants v
LEFT JOIN conversations c ON c.variant_id = v.id
LEFT JOIN leads l ON l.conversation_id = c.id
WHERE v.experiment_id = '...'
GROUP BY v.name;</code></pre>

<p>
    A built-in stats panel inside <code>/app/agents/{id}/experiments</code>
    is on the roadmap; for now you'll need to run that query manually
    (workspace API token + <code>/api/v1/db</code> read access if
    you're on the self-host build, or ask support).
</p>

<h2>Stop / delete</h2>

<p>
    <strong>Stop</strong> sets status to <code>stopped</code> and flushes the
    running-experiment cache so new conversations immediately stop
    getting assigned. Existing conversations keep their assigned
    variant for consistency in mid-flight chats.
</p>

<p>
    <strong>Delete</strong> hard-deletes the experiment row. Variant rows
    cascade. Existing <code>conversations.variant_id</code> values become
    foreign-key orphans — that's intentional; we keep the historical
    record of which conversation got which variant even after the
    experiment ends.
</p>
