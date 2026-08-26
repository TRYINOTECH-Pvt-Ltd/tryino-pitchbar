/**
 * Glob-match window.location.pathname against the agent's
 * restricted_paths list. Returns true when the widget should NOT mount.
 *
 * Pattern syntax — kept tiny on purpose so non-technical admins can
 * write rules without escaping:
 *
 *   /admin           → exact-match `/admin` (and `/Admin`, etc.)
 *   /admin/*         → any path under /admin (the trailing star is
 *                       greedy across slashes, like a recursive glob)
 *   /a/*\/x          → /a/anything/x (single-segment-ish; * matches
 *                       any chars including slashes)
 *
 * Comparison is case-insensitive — URLs aren't case-sensitive on the
 * client side and admins shouldn't have to remember which case their
 * marketing tracker spelled `/Admin` in.
 */
export function isPathRestricted(
    pathname: string,
    patterns: string[] | null | undefined,
): boolean {
    if (!patterns || patterns.length === 0) {
        return false;
    }

    const haystack = pathname.toLowerCase();

    for (const raw of patterns) {
        if (typeof raw !== 'string') {
            continue;
        }

        const pattern = raw.trim().toLowerCase();

        if (pattern === '') {
            continue;
        }

        if (matches(haystack, pattern)) {
            return true;
        }
    }

    return false;
}

function matches(haystack: string, pattern: string): boolean {
    // Fast paths — most patterns are either exact or end-with-`/*`,
    // and we want the matcher to stay cheap on the hot mount path.
    if (!pattern.includes('*')) {
        return haystack === pattern;
    }

    if (pattern.endsWith('/*')) {
        const prefix = pattern.slice(0, -2);

        return haystack === prefix || haystack.startsWith(prefix + '/');
    }

    // General case: convert `*` to `.*` and anchor. Escape the rest
    // so a pattern like `/foo.bar` doesn't match `/foofbar`.
    const escaped = pattern
        .replace(/[.+?^${}()|[\]\\]/g, '\\$&')
        .replace(/\*/g, '.*');

    return new RegExp('^' + escaped + '$').test(haystack);
}
