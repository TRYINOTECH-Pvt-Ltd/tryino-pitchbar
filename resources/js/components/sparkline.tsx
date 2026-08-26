/**
 * Tiny inline area chart for stat-card "trend at a glance" treatments.
 * Pure SVG, no chart-library dependency. Caller passes the daily series
 * (already pre-computed server-side) and an accent color.
 */
export function Sparkline({
    values,
    accent,
    width = 96,
    height = 28,
}: {
    values: number[];
    accent: string;
    width?: number;
    height?: number;
}) {
    if (values.length === 0) {
        return null;
    }

    const max = Math.max(1, ...values);
    const stepX = width / Math.max(1, values.length - 1);

    const points = values
        .map(
            (v, i) =>
                `${(i * stepX).toFixed(1)},${(height - (v / max) * height).toFixed(1)}`,
        )
        .join(' ');
    const areaPoints = `0,${height} ${points} ${width},${height}`;

    return (
        <svg
            viewBox={`0 0 ${width} ${height}`}
            className="h-7 w-20 shrink-0 sm:w-24"
            preserveAspectRatio="none"
            aria-hidden
        >
            <polygon points={areaPoints} fill={accent} fillOpacity="0.12" />
            <polyline
                points={points}
                fill="none"
                stroke={accent}
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}
