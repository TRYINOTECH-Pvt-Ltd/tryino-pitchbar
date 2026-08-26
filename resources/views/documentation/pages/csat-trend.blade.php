<p>
    Pitchbar lets visitors rate a conversation — good or bad, with an
    optional comment — when the satisfaction prompt fires. The CSAT
    panel on <code>/app/analytics</code> turns that raw data into a
    trend you can act on.
</p>

<h2>How a rating is captured</h2>

<p>
    The widget shows a thumbs-up / thumbs-down prompt after a
    conversation reaches a natural pause (visitor closes the panel,
    or N seconds of silence). Submitting writes three columns on the
    <code>conversations</code> row:
</p>

<ul>
    <li><code>satisfaction</code> — <code>good</code> or
        <code>bad</code>.</li>
    <li><code>satisfaction_at</code> — when they submitted.</li>
    <li><code>satisfaction_comment</code> — optional free-text up to
        500 chars.</li>
</ul>

<p>
    A visitor can only rate a conversation once; resubmissions are
    ignored.
</p>

<h2>What the panel shows</h2>

<ul>
    <li><strong>Weekly score</strong> — 12 bars, one per week,
        coloured green / amber / red based on
        <code>good / total &times; 100</code>. Hover a bar for the
        exact percentage and sample size.</li>
    <li><strong>Latest score</strong> — most recent non-empty week's
        score, plus the total rating count across the 12-week
        window.</li>
    <li><strong>Recent low-rated</strong> — up to 10 conversations
        rated <code>bad</code> in the last 30 days. Each row deep
        links to the conversation transcript so you can read what
        went wrong.</li>
</ul>

<h2>Why the score can swing</h2>

<p>
    CSAT is sample-size sensitive. A week with 4 ratings (2 good, 2
    bad) shows 50%, but that's not a real trend — you have too few
    data points. Read the score relative to the
    <code>:n ratings</code> badge: under ~50 ratings per week, treat
    swings as noise.
</p>

<h2>Tenant isolation</h2>

<p>
    All CSAT queries are scoped through your workspace's agents.
    There is no cross-workspace aggregate or benchmark — your
    numbers are yours alone.
</p>

<h2>Privacy</h2>

<p>
    Visitor comments may contain PII (visitor's name, email if they
    mentioned it). The same GDPR DSR endpoints at
    <code>/app/dsr</code> erase satisfaction comments alongside the
    rest of the visitor's record when an erasure is processed.
</p>
