import { ArrowLeft, ArrowRight, ChevronLeft, ChevronRight } from 'lucide-react';
import type { LucideProps } from 'lucide-react';
import { useIsRtl } from '@/lib/direction';

/**
 * Renders a directional arrow that respects the document writing
 * direction. `forward` always points toward the natural reading
 * direction (right in LTR, left in RTL). `back` always points away
 * from it.
 *
 *   <DirArrow direction="forward" />   // → in LTR, ← in RTL
 *   <DirArrow direction="back" />      // ← in LTR, → in RTL
 *
 * Use this for icons whose semantic meaning is "next" / "previous" /
 * "continue" / "back" — pagination, breadcrumbs, wizard steps.
 *
 * For icons whose direction has no semantic meaning (paper-plane in
 * a chat composer, undo, redo) prefer the `.flip-rtl` utility class
 * instead of swapping the icon.
 */
export function DirArrow({
    direction,
    style = 'arrow',
    ...rest
}: {
    direction: 'forward' | 'back';
    style?: 'arrow' | 'chevron';
} & LucideProps) {
    const rtl = useIsRtl();

    const physical: 'left' | 'right' =
        (direction === 'forward') !== rtl ? 'right' : 'left';

    if (style === 'chevron') {
        const Icon = physical === 'right' ? ChevronRight : ChevronLeft;

        return <Icon {...rest} />;
    }

    const Icon = physical === 'right' ? ArrowRight : ArrowLeft;

    return <Icon {...rest} />;
}
