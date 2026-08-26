export function relativeTime(
    iso: string | null,
    fallback = 'No activity',
): string {
    if (!iso) {
        return fallback;
    }

    const ms = Date.now() - new Date(iso).getTime();
    const min = Math.max(1, Math.round(ms / 60000));

    if (min < 60) {
        return `${min}m ago`;
    }

    const hr = Math.round(min / 60);

    if (hr < 24) {
        return `${hr}h ago`;
    }

    const day = Math.round(hr / 24);

    if (day < 7) {
        return `${day}d ago`;
    }

    return new Date(iso).toLocaleDateString();
}
