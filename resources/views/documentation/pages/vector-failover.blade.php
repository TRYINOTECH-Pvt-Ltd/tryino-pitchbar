<p>
    The Vectorize / Qdrant client is wrapped in a circuit breaker so
    a vector-store outage degrades gracefully instead of failing
    the visitor turn.
</p>

<h2>What the breaker does</h2>

<ul>
    <li>Counts consecutive errors from the underlying client in a
        sliding window.</li>
    <li>Once <code>VECTOR_CIRCUIT_THRESHOLD</code> errors land
        inside <code>VECTOR_CIRCUIT_WINDOW</code> seconds, the
        circuit <strong>opens</strong>.</li>
    <li>While open (<code>VECTOR_CIRCUIT_COOLDOWN</code> seconds):
        <ul>
            <li><code>search()</code> returns an empty array
                immediately. The LLM still answers — it just has no
                retrieved <code>&lt;source&gt;</code> chunks for
                that turn.</li>
            <li><code>upsertPoints()</code> / <code>delete*</code> /
                <code>ensureCollection()</code> /
                <code>dropCollection()</code> raise
                <code>CircuitOpenException</code>. Queued jobs
                requeue automatically.</li>
        </ul>
    </li>
    <li>After cooldown, the next call probes the underlying client.
        A successful probe clears the failure counter; a failed
        probe re-opens the circuit for another cooldown window.</li>
</ul>

<h2>Configuration</h2>

<table>
    <thead><tr><th>Env var</th><th>Default</th><th>What it does</th></tr></thead>
    <tbody>
        <tr><td><code>VECTOR_CIRCUIT_THRESHOLD</code></td><td><code>5</code></td><td>Errors needed to trip the breaker.</td></tr>
        <tr><td><code>VECTOR_CIRCUIT_WINDOW</code></td><td><code>60</code></td><td>Sliding-window seconds for the failure counter.</td></tr>
        <tr><td><code>VECTOR_CIRCUIT_COOLDOWN</code></td><td><code>60</code></td><td>Seconds the breaker stays open before the next probe.</td></tr>
    </tbody>
</table>

<p>
    Cache backend is whatever <code>cache.default</code> resolves to.
    On a Redis production stack the breaker state is shared across
    workers, so an outage trips for the whole cluster at once
    instead of being re-discovered per worker.
</p>

<h2>What an open circuit looks like</h2>

<p>
    From <code>laravel.log</code>:
</p>

<pre><code>vector.circuit_open  {"client":"vectorize","count":5,"cooldown":60,"error":"Cloudflare 502 Bad Gateway"}</code></pre>

<p>
    The widget continues to serve responses, just without retrieval.
    Once Vectorize recovers, the breaker auto-closes on the next
    successful probe — no operator action required.
</p>

<h2>When to tune the values</h2>

<ul>
    <li><strong>Lower the threshold</strong> if you'd rather degrade
        than push retries through during a flap. Default of 5 is
        reasonable; for very low-traffic sites consider 3.</li>
    <li><strong>Raise the cooldown</strong> if your provider tends
        to misbehave for 5–10 minutes. Default 60s probes
        aggressively; setting 300 reduces probe traffic during a
        long outage.</li>
    <li><strong>Shrink the window</strong> if you only want to
        trip on a true outage, not a daily blip. Default 60s
        sliding window means transient flaps roll off quickly.</li>
</ul>
