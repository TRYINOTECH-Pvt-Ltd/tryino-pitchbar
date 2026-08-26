type TriggerKind =
    | 'exit_intent'
    | 'idle'
    | 'scroll'
    | 'time'
    | 'returning'
    | 'utm'
    | 'abandoned_cart';

export type TriggerRule = {
    id: string;
    kind: TriggerKind;
    conditions?: Record<string, unknown>;
    action?: { kind: string; message?: string; cta_id?: string };
};

export type TriggerHandler = (rule: TriggerRule) => void;

/**
 * Cooldown is PER-RULE so customers can stack multiple triggers (e.g.
 * exit_intent + idle + abandoned_cart) and have each one fire on its
 * own clock. Pre-fix this was a global `pb_last_trigger_at` window —
 * the first trigger that fired blocked every other rule for 5 minutes,
 * which is what buyer Dovydas reported as "behavior triggers don't
 * work". The key is namespaced so each rule remembers its own last-fire
 * timestamp across reloads.
 */
const COOLDOWN_KEY_PREFIX = 'pb_last_trigger_at:';
const COOLDOWN_MS = 5 * 60 * 1000;

function cooldownKeyFor(ruleId: string): string {
    return COOLDOWN_KEY_PREFIX + ruleId;
}

export class TriggerEngine {
    private fired = new Set<string>();

    private detachers: Array<() => void> = [];

    constructor(
        private readonly rules: TriggerRule[],
        private readonly onFire: TriggerHandler,
    ) {}

    start(): void {
        for (const rule of this.rules) {
            switch (rule.kind) {
                case 'exit_intent':
                    this.attachExitIntent(rule);
                    break;
                case 'idle':
                    this.attachIdle(rule);
                    break;
                case 'scroll':
                    this.attachScroll(rule);
                    break;
                case 'time':
                    this.attachTime(rule);
                    break;
                case 'returning':
                    if (this.isReturningVisitor()) {
                        this.fire(rule);
                    }

                    break;
                case 'utm':
                    if (this.matchesUtm(rule.conditions ?? {})) {
                        this.fire(rule);
                    }

                    break;
                case 'abandoned_cart':
                    this.attachAbandonedCart(rule);
                    break;
            }
        }
    }

    stop(): void {
        this.detachers.forEach((d) => d());
        this.detachers = [];
    }

    private fire(rule: TriggerRule): void {
        if (this.fired.has(rule.id)) {
            return;
        }

        const key = cooldownKeyFor(rule.id);

        try {
            const last = Number(localStorage.getItem(key) ?? '0');

            if (Date.now() - last < COOLDOWN_MS) {
                return;
            }
        } catch {
            // localStorage disabled (private mode / strict cookie
            // settings). Fall through and fire — losing the cooldown
            // is better than never firing.
        }

        this.fired.add(rule.id);

        try {
            localStorage.setItem(key, String(Date.now()));
        } catch {
            // best-effort — same fallback as above
        }

        this.onFire(rule);
    }

    private attachExitIntent(rule: TriggerRule): void {
        const handler = (e: MouseEvent) => {
            if (e.clientY < 10) {
                this.fire(rule);
            }
        };
        document.addEventListener('mouseout', handler);
        this.detachers.push(() =>
            document.removeEventListener('mouseout', handler),
        );
    }

    private attachIdle(rule: TriggerRule): void {
        const seconds = Number(rule.conditions?.seconds ?? 30);
        let timer = window.setTimeout(() => this.fire(rule), seconds * 1000);
        const reset = () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => this.fire(rule), seconds * 1000);
        };
        const events = ['mousemove', 'keydown', 'scroll', 'touchstart'];
        events.forEach((ev) =>
            document.addEventListener(ev, reset, { passive: true }),
        );
        this.detachers.push(() => {
            window.clearTimeout(timer);
            events.forEach((ev) => document.removeEventListener(ev, reset));
        });
    }

    private attachScroll(rule: TriggerRule): void {
        const percent = Number(rule.conditions?.percent ?? 50);
        const handler = () => {
            const total =
                document.documentElement.scrollHeight - window.innerHeight;

            if (total <= 0) {
                return;
            }

            const ratio = (window.scrollY / total) * 100;

            if (ratio >= percent) {
                this.fire(rule);
            }
        };
        window.addEventListener('scroll', handler, { passive: true });
        this.detachers.push(() =>
            window.removeEventListener('scroll', handler),
        );
    }

    private attachTime(rule: TriggerRule): void {
        const seconds = Number(rule.conditions?.seconds ?? 30);
        const t = window.setTimeout(() => this.fire(rule), seconds * 1000);
        this.detachers.push(() => window.clearTimeout(t));
    }

    private isReturningVisitor(): boolean {
        const count = Number(localStorage.getItem('pb_visitor_count') ?? '0');
        localStorage.setItem('pb_visitor_count', String(count + 1));

        return count >= 1;
    }

    /**
     * Fires when the visitor has a non-empty cart older than the
     * configured idle window. Plugin's cart-state.js mirrors WC
     * `added_to_cart` / `removed_from_cart` into localStorage under
     * `pitchbar_cart_state` ({ items, timestamp }), so this trigger
     * is a cheap timer + a single read — no server round-trip.
     */
    private attachAbandonedCart(rule: TriggerRule): void {
        const idleMinutes = Number(rule.conditions?.idle_minutes ?? 5);
        const checkEveryMs = 30 * 1000;

        const tick = () => {
            try {
                const raw = localStorage.getItem('pitchbar_cart_state');

                if (raw === null) {
                    return;
                }

                const state = JSON.parse(raw) as {
                    items?: number;
                    timestamp?: number;
                };

                if (!state.items || state.items <= 0 || !state.timestamp) {
                    return;
                }

                const ageMs = Date.now() - Number(state.timestamp);

                if (ageMs >= idleMinutes * 60 * 1000) {
                    this.fire(rule);
                }
            } catch {
                // Malformed JSON / disabled localStorage — silent no-op.
            }
        };

        const handle = window.setInterval(tick, checkEveryMs);
        // Run once immediately so a visitor landing on a fresh page
        // with an already-stale cart engages without waiting 30s.
        tick();
        this.detachers.push(() => window.clearInterval(handle));
    }

    private matchesUtm(conditions: Record<string, unknown>): boolean {
        const params = new URLSearchParams(window.location.search);

        for (const key of Object.keys(conditions)) {
            const expected = conditions[key];
            const actual = params.get(key);

            if (typeof expected === 'string' && actual !== expected) {
                return false;
            }
        }

        return true;
    }
}
