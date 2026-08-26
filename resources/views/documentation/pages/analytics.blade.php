<p>
    The Analytics page (<code>/app/analytics</code>) is your one-stop view
    on whether the agent is actually working. Conversation volume,
    deflection rate, lead conversion, and the all-important
    <strong>knowledge gap report</strong>.
</p>

<h2>Top-level metrics</h2>

<table>
    <thead><tr><th>Metric</th><th>What it means</th></tr></thead>
    <tbody>
        <tr><td>Conversations</td><td>Distinct chat threads in the period. Counts each visitor's first init, not every message.</td></tr>
        <tr><td>Messages</td><td>Total visitor messages. Average per conversation tells you engagement depth.</td></tr>
        <tr><td>Deflection rate</td><td>Conversations the AI handled end-to-end (no human takeover, no low-confidence flag) divided by total. Higher is better.</td></tr>
        <tr><td>Leads captured</td><td>Visitors who completed the lead form. Conversion shown as % of conversations.</td></tr>
        <tr><td>Average response time</td><td>Median time from visitor message to first token streamed back. Should sit well under the 1s p95 hot-path budget.</td></tr>
    </tbody>
</table>

<h2>The gap report</h2>

<p>
    Whenever a turn comes back with <code>low_confidence=true</code>, a
    <code>DetectGapJob</code> is dispatched. The job clusters similar gaps
    using semantic similarity on the visitor's question, then opens a row in
    the <strong>Gaps</strong> table. Each row shows:
</p>

<ul>
    <li>The cluster's representative question.</li>
    <li>How many times it has been asked.</li>
    <li>Sample conversation links so you can read the full context.</li>
    <li>An <strong>Add source</strong> button that pre-fills the source modal with a search query.</li>
</ul>

<p>
    Closing the loop: read the gap → add a knowledge source that answers it →
    next visitor with that question gets a confident answer.
</p>

<h2>Per-agent vs. workspace</h2>

<p>
    The page defaults to all agents in the workspace; the agent picker at the
    top filters to one. Useful when you have one agent per surface (marketing,
    help center) and want to compare deflection rates.
</p>

<h2>Time ranges</h2>

<p>
    Pick a window: 24h, 7d, 30d, or a custom range. Numbers update without a
    full reload — Inertia partial visit. For longer trends, the chart at
    the top is a 30-day rolling view.
</p>

<h2>Export</h2>

<p>
    The CSV export pulls everything in the current filter — conversations,
    messages, leads, gaps. One row per record. Columns are stable so you can
    automate downstream reporting.
</p>

<h2>Citation effectiveness</h2>

<p>
    A small table shows which knowledge sources were cited most often and
    which never get cited. Sources with zero citations in 30 days are
    candidates for deletion or reindexing.
</p>

<h2>Experiment results</h2>

<p>
    If you have an active A/B experiment on behavior rules (see
    <a href="/documentation/behavior-rules">Behavior rules &amp; triggers</a>),
    its conversion delta appears as a callout at the top of the page until
    you call it (declare a winner). The page-level chart respects the
    experiment split too — you can see whether the variant lifted overall
    deflection.
</p>
