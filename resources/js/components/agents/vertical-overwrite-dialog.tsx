import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';
import { VERTICAL_BY_ID } from '@/lib/verticals';
import type { VerticalId } from '@/lib/verticals';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    targetVertical: VerticalId | null;
    onConfirm: () => void;
    pending?: boolean;
};

/**
 * Confirmation dialog before destructively overwriting an admin's
 * customized starter prompts / launcher label / max_chars with a
 * preset's defaults. Non-destructive applies skip this.
 */
export function VerticalOverwriteDialog({
    open,
    onOpenChange,
    targetVertical,
    onConfirm,
    pending = false,
}: Props) {
    const { t } = useT();
    const meta = targetVertical ? VERTICAL_BY_ID[targetVertical] : null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('Apply the :name preset?', {
                            name: meta?.name ?? t('this'),
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t(
                            "This will replace your current starter prompts, launcher label, and reply length with the preset's defaults. Theme, name, language, and your custom system prompt are not affected.",
                        )}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={pending}
                    >
                        {t('Cancel')}
                    </Button>
                    <Button
                        onClick={onConfirm}
                        disabled={pending || !targetVertical}
                    >
                        {pending ? t('Applying…') : t('Replace and apply')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
