import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

type ConfirmOptions = {
    title?: string;
    message: string;
    confirmLabel?: string;
    cancelLabel?: string;
    danger?: boolean;
};

type AlertOptions = {
    title?: string;
    message: string;
    confirmLabel?: string;
};

type PromptOptions = {
    title?: string;
    /** Body text rendered above the input. */
    message: string;
    /** Pre-filled value. */
    defaultValue?: string;
    placeholder?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    /**
     * Synchronous validator. Return null when valid; return an error
     * message string to keep the dialog open and surface the message
     * under the input.
     */
    validate?: (value: string) => string | null;
};

type DialogState =
    | { kind: 'confirm'; options: ConfirmOptions }
    | { kind: 'alert'; options: AlertOptions }
    | { kind: 'prompt'; options: PromptOptions };

type ConfirmFn = (options: ConfirmOptions) => Promise<boolean>;
type AlertFn = (options: AlertOptions) => Promise<void>;
type PromptFn = (options: PromptOptions) => Promise<string | null>;

type ContextValue = {
    confirm: ConfirmFn;
    alert: AlertFn;
    prompt: PromptFn;
};

const ConfirmDialogContext = createContext<ContextValue | null>(null);

/**
 * Provider that supplies `useConfirm()`, `useAlert()`, and `usePrompt()`
 * hooks backed by a shadcn Dialog. Mount once near the top of the React
 * tree — usage:
 *
 *   const confirm = useConfirm();
 *   const ok = await confirm({ message: 'Sure?', danger: true });
 *
 *   const alert = useAlert();
 *   await alert({ message: 'Saved!' });
 *
 *   const prompt = usePrompt();
 *   const next = await prompt({
 *       title: 'Rename article',
 *       message: 'New title',
 *       defaultValue: current,
 *       validate: (v) => (v.trim() === '' ? 'Required' : null),
 *   });
 *   // next is the string the user submitted, or null on cancel.
 */
export function ConfirmDialogProvider({ children }: { children: ReactNode }) {
    // This provider is mounted OUTSIDE the Inertia tree (via `withApp`
    // in app.tsx — see DirectedApp wrapper). Hooks like `useT()` that
    // call `usePage()` throw "usePage must be used within the Inertia
    // component" here. Labels stay in English by default; callsites
    // pass `cancelLabel` / `confirmLabel` / `title` already translated
    // because the calling component IS inside Inertia.
    const [state, setState] = useState<DialogState | null>(null);
    const [open, setOpen] = useState(false);
    // Resolver type widened to cover all three dialog flavours. The
    // close() helper dispatches based on which kind is currently active.
    const resolverRef = useRef<
        ((value: boolean | string | null) => void) | null
    >(null);

    const close = useCallback((result: boolean | string | null) => {
        setOpen(false);
        const resolver = resolverRef.current;
        resolverRef.current = null;

        if (resolver) {
            resolver(result);
        }
    }, []);

    const confirm = useCallback<ConfirmFn>((options) => {
        return new Promise<boolean>((resolve) => {
            resolverRef.current = (value) => resolve(value === true);
            setState({ kind: 'confirm', options });
            setOpen(true);
        });
    }, []);

    const alert = useCallback<AlertFn>((options) => {
        return new Promise<void>((resolve) => {
            resolverRef.current = () => resolve();
            setState({ kind: 'alert', options });
            setOpen(true);
        });
    }, []);

    const prompt = useCallback<PromptFn>((options) => {
        return new Promise<string | null>((resolve) => {
            resolverRef.current = (value) =>
                resolve(typeof value === 'string' ? value : null);
            setState({ kind: 'prompt', options });
            setOpen(true);
        });
    }, []);

    const value = useMemo<ContextValue>(
        () => ({ confirm, alert, prompt }),
        [confirm, alert, prompt],
    );

    const isConfirm = state?.kind === 'confirm';
    const isPrompt = state?.kind === 'prompt';
    const title = state?.options.title;
    const message = state?.options.message ?? '';
    const confirmLabel = isConfirm
        ? (state.options.confirmLabel ?? 'Confirm')
        : isPrompt
          ? (state.options.confirmLabel ?? 'Save')
          : (state?.options.confirmLabel ?? 'OK');
    const cancelLabel = isConfirm
        ? (state.options.cancelLabel ?? 'Cancel')
        : isPrompt
          ? (state.options.cancelLabel ?? 'Cancel')
          : null;
    const danger = isConfirm ? Boolean(state.options.danger) : false;

    return (
        <ConfirmDialogContext.Provider value={value}>
            {children}
            {/*
              CRITICAL: only mount <DialogContent> when the modal is
              actually open. DialogContent internally calls `useT()`
              (for the close-button sr-only label), which calls
              `usePage()`. This provider lives OUTSIDE the Inertia tree
              (via `withApp` / `DirectedApp` in app.tsx), so usePage()
              throws "usePage must be used within the Inertia component".
              Conditional mount ensures useT only fires when a modal
              opens, by which time the page has hydrated.
            */}
            {open && state !== null ? (
                <Dialog
                    open={open}
                    onOpenChange={(next) => {
                        if (!next) {
                            // Cancel: resolves confirm → false, alert →
                            // void, prompt → null. The dispatcher inside
                            // each resolver closure already coerces.
                            close(isPrompt ? null : false);
                        }
                    }}
                >
                    <DialogContent>
                        <DialogHeader>
                            {title ? <DialogTitle>{title}</DialogTitle> : null}
                            <DialogDescription>{message}</DialogDescription>
                        </DialogHeader>
                        {isPrompt ? (
                            <PromptBody
                                options={state.options}
                                onSubmit={(value) => close(value)}
                                onCancel={() => close(null)}
                                confirmLabel={confirmLabel}
                                cancelLabel={cancelLabel ?? 'Cancel'}
                            />
                        ) : (
                            <DialogFooter>
                                {cancelLabel !== null ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => close(false)}
                                    >
                                        {cancelLabel}
                                    </Button>
                                ) : null}
                                <Button
                                    type="button"
                                    variant={danger ? 'destructive' : 'default'}
                                    onClick={() => close(true)}
                                    autoFocus
                                >
                                    {confirmLabel}
                                </Button>
                            </DialogFooter>
                        )}
                    </DialogContent>
                </Dialog>
            ) : null}
        </ConfirmDialogContext.Provider>
    );
}

/**
 * Input + footer body for the prompt() dialog flavour. Local component
 * so the input has its own state (decoupled from the provider) and we
 * can autofocus + select-on-mount without re-rendering the provider on
 * every keystroke.
 */
function PromptBody({
    options,
    onSubmit,
    onCancel,
    confirmLabel,
    cancelLabel,
}: {
    options: PromptOptions;
    onSubmit: (value: string) => void;
    onCancel: () => void;
    confirmLabel: string;
    cancelLabel: string;
}) {
    const [value, setValue] = useState<string>(options.defaultValue ?? '');
    const [error, setError] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement | null>(null);

    useEffect(() => {
        const el = inputRef.current;

        if (el) {
            el.focus();
            el.select();
        }
    }, []);

    const submit = () => {
        const errorMessage = options.validate?.(value) ?? null;

        if (errorMessage !== null) {
            setError(errorMessage);

            return;
        }

        onSubmit(value);
    };

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                submit();
            }}
            className="grid gap-3"
        >
            <Input
                ref={inputRef}
                type="text"
                value={value}
                onChange={(e) => {
                    setValue(e.target.value);

                    if (error !== null) {
                        setError(null);
                    }
                }}
                placeholder={options.placeholder}
                aria-invalid={error !== null}
            />
            {error !== null ? (
                <p className="text-xs text-destructive">{error}</p>
            ) : null}
            <DialogFooter>
                <Button type="button" variant="outline" onClick={onCancel}>
                    {cancelLabel}
                </Button>
                <Button type="submit" variant="default">
                    {confirmLabel}
                </Button>
            </DialogFooter>
        </form>
    );
}

function useDialogContext(): ContextValue {
    const ctx = useContext(ConfirmDialogContext);

    if (ctx === null) {
        throw new Error(
            'useConfirm/useAlert/usePrompt must be used inside <ConfirmDialogProvider>',
        );
    }

    return ctx;
}

export function useConfirm(): ConfirmFn {
    return useDialogContext().confirm;
}

export function useAlert(): AlertFn {
    return useDialogContext().alert;
}

export function usePrompt(): PromptFn {
    return useDialogContext().prompt;
}
