import { cn } from '@/lib/utils';

type Bucket = 'low' | 'medium' | 'high';

type Props = {
    score: number;
    bucket?: Bucket | null;
    reasons?: string[] | null;
    showNumber?: boolean;
    className?: string;
};

function bucketFor(score: number, hinted?: Bucket | null): Bucket {
    if (hinted === 'low' || hinted === 'medium' || hinted === 'high') {
        return hinted;
    }

    if (score >= 70) {
        return 'high';
    }

    if (score >= 40) {
        return 'medium';
    }

    return 'low';
}

const BUCKET_STYLES: Record<Bucket, string> = {
    high: 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200',
    medium: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200',
    low: 'border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900/30 dark:text-slate-300',
};

const BUCKET_LABELS: Record<Bucket, string> = {
    high: 'Hot',
    medium: 'Warm',
    low: 'Cold',
};

export function LeadScoreBadge({
    score,
    bucket,
    reasons,
    showNumber = true,
    className,
}: Props) {
    const safe = Math.max(0, Math.min(100, Math.round(score)));
    const resolved = bucketFor(safe, bucket);
    const tooltipLines = [
        `Lead score ${safe}/100 — ${BUCKET_LABELS[resolved]}`,
    ];

    if (Array.isArray(reasons) && reasons.length > 0) {
        tooltipLines.push('', ...reasons);
    }

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium tabular-nums',
                BUCKET_STYLES[resolved],
                className,
            )}
            title={tooltipLines.join('\n')}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'size-1.5 rounded-full',
                    resolved === 'high'
                        ? 'bg-emerald-500'
                        : resolved === 'medium'
                          ? 'bg-amber-500'
                          : 'bg-slate-400',
                )}
            />
            <span>{BUCKET_LABELS[resolved]}</span>
            {showNumber && <span className="opacity-70">· {safe}</span>}
        </span>
    );
}
