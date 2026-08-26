import { X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

type Props = {
    count: number;
    onClear: () => void;
    /** Action buttons rendered on the right of the bar. */
    children: ReactNode;
    /** Singular noun, e.g. "agent" / "lead". Used in the count label. */
    noun: string;
};

/**
 * Sticky bottom bar that surfaces selection count + bulk actions
 * whenever any row is selected. Auto-hides at count=0.
 *
 * Render at the end of an index page; the bar floats above content
 * via fixed positioning.
 */
export function BulkActionsBar({ count, onClear, children, noun }: Props) {
    const { t, tChoice } = useT();

    if (count === 0) {
        return null;
    }

    const label = tChoice(
        ':count :noun selected|:count :nouns selected',
        count,
        { count, noun, nouns: `${noun}s` },
    );

    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-4 z-40 flex justify-center px-4">
            <div className="pointer-events-auto flex items-center gap-3 rounded-full border bg-card px-4 py-2 shadow-lg">
                <button
                    type="button"
                    onClick={onClear}
                    aria-label={t('Clear selection')}
                    className="inline-flex size-6 items-center justify-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground"
                >
                    <X className="size-3.5" />
                </button>
                <span className="text-sm font-medium">{label}</span>
                <div className="flex items-center gap-2">{children}</div>
            </div>
        </div>
    );
}

/**
 * Convenience destructive action button for the bar. Calls
 * `window.confirm()` then invokes `onConfirm`. Most pages just need
 * a single "Delete" button.
 */
export function BulkDeleteButton({
    confirmMessage,
    onConfirm,
    label,
}: {
    confirmMessage: string;
    onConfirm: () => void;
    label?: string;
}) {
    const { t } = useT();
    const confirm = useConfirm();
    const text = label ?? t('Delete');

    return (
        <Button
            type="button"
            variant="destructive"
            size="sm"
            onClick={async () => {
                const ok = await confirm({
                    message: confirmMessage,
                    confirmLabel: text,
                    danger: true,
                });

                if (ok) {
                    onConfirm();
                }
            }}
        >
            {text}
        </Button>
    );
}
