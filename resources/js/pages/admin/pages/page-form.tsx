import type { Method } from '@inertiajs/core';
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { usePrompt } from '@/components/confirm-dialog-provider';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';

export type PageFormValues = {
    title: string;
    slug: string;
    content_markdown: string;
    is_published: boolean;
    sort_order: number;
};

type Props = {
    initial: PageFormValues;
    method: Extract<Method, 'post' | 'patch'>;
    action: string;
    submitLabel: string;
};

type EditorView = 'edit' | 'split' | 'preview';

export function PageForm({ initial, method, action, submitLabel }: Props) {
    const { t } = useT();
    const prompt = usePrompt();
    const form = useForm<PageFormValues>(initial);
    const [editorView, setEditorView] = useState<EditorView>('split');
    const [previewHtml, setPreviewHtml] = useState<string>('');
    const [previewLoading, setPreviewLoading] = useState(false);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const debounceRef = useRef<number | null>(null);
    const textareaRef = useRef<HTMLTextAreaElement | null>(null);

    type ToolbarBtn = {
        wrap?: string;
        linePrefix?: string;
        insertLink?: boolean;
    };

    /**
     * Apply a markdown formatting action to the textarea. Handles three
     * shapes: `wrap` (surround selection with markers), `linePrefix`
     * (insert at the start of every selected line), or `insertLink`
     * (prompt for URL via the shadcn usePrompt dialog). Keeps focus +
     * caret position so the operator can keep typing without reaching
     * for the mouse.
     */
    const applyMarkdown = async (btn: ToolbarBtn) => {
        const el = textareaRef.current;

        if (!el) {
            return;
        }

        const start = el.selectionStart ?? 0;
        const end = el.selectionEnd ?? 0;
        const value = form.data.content_markdown;
        const selected = value.slice(start, end);

        let next = value;
        let newCursor = end;

        if (btn.wrap) {
            const replacement = btn.wrap + (selected || 'text') + btn.wrap;
            next = value.slice(0, start) + replacement + value.slice(end);
            newCursor = start + replacement.length;
        } else if (btn.linePrefix) {
            const lineStart = value.lastIndexOf('\n', start - 1) + 1;
            const block = value.slice(lineStart, end);
            const prefixed = block
                .split('\n')
                .map((line) =>
                    line.length === 0 ? line : btn.linePrefix + line,
                )
                .join('\n');
            next = value.slice(0, lineStart) + prefixed + value.slice(end);
            newCursor = lineStart + prefixed.length;
        } else if (btn.insertLink) {
            const url = await prompt({
                title: t('Insert link'),
                message: t('Link URL'),
                defaultValue: 'https://',
                placeholder: 'https://example.com',
                confirmLabel: t('Insert'),
                validate: (v) =>
                    v.trim() === '' || v.trim() === 'https://'
                        ? t('URL is required.')
                        : null,
            });

            if (url === null || url.trim() === '') {
                return;
            }

            const label = selected || 'link text';
            const replacement = `[${label}](${url})`;
            next = value.slice(0, start) + replacement + value.slice(end);
            newCursor = start + replacement.length;
        }

        form.setData('content_markdown', next);
        // Restore focus + cursor after React applies the value change.
        window.setTimeout(() => {
            if (!textareaRef.current) {
                return;
            }

            textareaRef.current.focus();
            textareaRef.current.setSelectionRange(newCursor, newCursor);
        }, 0);
    };

    // Debounced live preview — POSTs the markdown to a server-side
    // renderer that uses the same GFM converter + html-escape config
    // as the public page route, so what the operator sees here is
    // byte-identical to what the visitor will see at /p/{slug}.
    useEffect(() => {
        if (editorView === 'edit') {
            return;
        }

        if (debounceRef.current) {
            window.clearTimeout(debounceRef.current);
        }

        const csrf = (
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') ?? ''
        ).toString();

        debounceRef.current = window.setTimeout(async () => {
            setPreviewLoading(true);
            setPreviewError(null);

            try {
                const response = await fetch('/admin/pages/preview', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        content_markdown: form.data.content_markdown,
                    }),
                });

                if (!response.ok) {
                    throw new Error('Preview render failed');
                }

                const json = await response.json();
                setPreviewHtml((json.html ?? '') as string);
            } catch (e) {
                setPreviewError(
                    e instanceof Error ? e.message : 'Preview render failed',
                );
            } finally {
                setPreviewLoading(false);
            }
        }, 400);

        return () => {
            if (debounceRef.current) {
                window.clearTimeout(debounceRef.current);
            }
        };
    }, [form.data.content_markdown, editorView]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.submit(method, action, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="grid gap-4">
            <Card className="p-4">
                <h2 className="font-semibold">{t('Page metadata')}</h2>
                <p className="mb-4 text-xs text-muted-foreground">
                    {t(
                        'Published pages live at /p/{slug}. Drafts return 404 until you flip the toggle below.',
                    )}
                </p>

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor="page-title">{t('Title')}</Label>
                        <Input
                            id="page-title"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            placeholder={t('About us')}
                            required
                        />
                        {form.errors.title && (
                            <p className="text-xs text-destructive">
                                {form.errors.title}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="page-slug">{t('Slug')}</Label>
                        <Input
                            id="page-slug"
                            value={form.data.slug}
                            onChange={(e) =>
                                form.setData('slug', e.target.value)
                            }
                            placeholder={t('about')}
                        />
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Auto-derived from the title when blank. Lowercase + hyphens only.',
                            )}
                        </p>
                        {form.errors.slug && (
                            <p className="text-xs text-destructive">
                                {form.errors.slug}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="page-sort">{t('Footer order')}</Label>
                        <Input
                            id="page-sort"
                            type="number"
                            min={0}
                            value={form.data.sort_order}
                            onChange={(e) =>
                                form.setData(
                                    'sort_order',
                                    Math.max(
                                        0,
                                        Number.parseInt(e.target.value, 10) ||
                                            0,
                                    ),
                                )
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('Lower numbers render first in the footer.')}
                        </p>
                    </div>

                    <div className="grid gap-1.5">
                        <Label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.is_published}
                                onChange={(e) =>
                                    form.setData(
                                        'is_published',
                                        e.target.checked,
                                    )
                                }
                                className="size-4"
                            />
                            <span>{t('Publish (visible at /p/{slug})')}</span>
                        </Label>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Drafts 404 publicly. Publish when ready to link from the footer.',
                            )}
                        </p>
                    </div>
                </div>
            </Card>

            <Card className="p-4">
                <div className="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h2 className="font-semibold">
                            {t('Content (Markdown)')}
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Same flavour as the documentation pages. Headings (#, ##), lists, links, code fences. Raw HTML is escaped for safety.',
                            )}
                        </p>
                    </div>
                    <div className="inline-flex shrink-0 overflow-hidden rounded-md border text-xs">
                        <button
                            type="button"
                            onClick={() => setEditorView('edit')}
                            className={
                                editorView === 'edit'
                                    ? 'bg-foreground px-3 py-1.5 text-background'
                                    : 'px-3 py-1.5 hover:bg-muted'
                            }
                        >
                            {t('Edit')}
                        </button>
                        <button
                            type="button"
                            onClick={() => setEditorView('split')}
                            className={
                                editorView === 'split'
                                    ? 'border-x bg-foreground px-3 py-1.5 text-background'
                                    : 'border-x px-3 py-1.5 hover:bg-muted'
                            }
                        >
                            {t('Split')}
                        </button>
                        <button
                            type="button"
                            onClick={() => setEditorView('preview')}
                            className={
                                editorView === 'preview'
                                    ? 'bg-foreground px-3 py-1.5 text-background'
                                    : 'px-3 py-1.5 hover:bg-muted'
                            }
                        >
                            {t('Preview')}
                        </button>
                    </div>
                </div>

                {(editorView === 'edit' || editorView === 'split') && (
                    <div className="sticky top-2 z-10 mb-3 flex flex-wrap gap-1 rounded-md border bg-background/95 p-1 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-background/70">
                        {[
                            { label: 'B', title: 'Bold', wrap: '**' },
                            { label: 'I', title: 'Italic', wrap: '*' },
                            {
                                label: 'H1',
                                title: 'Heading 1',
                                linePrefix: '# ',
                            },
                            {
                                label: 'H2',
                                title: 'Heading 2',
                                linePrefix: '## ',
                            },
                            {
                                label: 'H3',
                                title: 'Heading 3',
                                linePrefix: '### ',
                            },
                            {
                                label: '•',
                                title: 'Bullet list',
                                linePrefix: '- ',
                            },
                            {
                                label: '1.',
                                title: 'Numbered list',
                                linePrefix: '1. ',
                            },
                            { label: '🔗', title: 'Link', insertLink: true },
                            { label: '<>', title: 'Inline code', wrap: '`' },
                            {
                                label: '```',
                                title: 'Code block',
                                wrap: '\n```\n',
                            },
                            {
                                label: '"',
                                title: 'Blockquote',
                                linePrefix: '> ',
                            },
                        ].map((btn, i) => (
                            <button
                                key={i}
                                type="button"
                                title={btn.title}
                                onClick={() => applyMarkdown(btn)}
                                className="rounded px-2 py-1 text-xs font-medium hover:bg-muted"
                            >
                                {btn.label}
                            </button>
                        ))}
                    </div>
                )}

                <div
                    className={
                        editorView === 'split'
                            ? 'grid items-stretch gap-3 md:grid-cols-2'
                            : 'grid gap-3'
                    }
                >
                    {(editorView === 'edit' || editorView === 'split') && (
                        <textarea
                            ref={textareaRef}
                            value={form.data.content_markdown}
                            onChange={(e) =>
                                form.setData('content_markdown', e.target.value)
                            }
                            className="min-h-[28rem] w-full resize-y rounded-md border bg-background px-3 py-2 font-mono text-xs leading-relaxed"
                            required
                        />
                    )}

                    {(editorView === 'preview' || editorView === 'split') && (
                        <div className="min-h-[28rem] overflow-auto rounded-md border bg-card px-4 py-3">
                            {previewLoading && (
                                <p className="text-xs text-muted-foreground">
                                    {t('Rendering preview…')}
                                </p>
                            )}
                            {previewError && (
                                <p className="text-xs text-destructive">
                                    {previewError}
                                </p>
                            )}
                            <div
                                className="page-content text-sm"
                                dangerouslySetInnerHTML={{
                                    __html:
                                        previewHtml ||
                                        '<p style="opacity:.6">' +
                                            t('Preview will render here.') +
                                            '</p>',
                                }}
                            />
                        </div>
                    )}
                </div>
                {form.errors.content_markdown && (
                    <p className="mt-1 text-xs text-destructive">
                        {form.errors.content_markdown}
                    </p>
                )}
            </Card>

            <div className="flex items-center justify-end gap-2">
                <Button asChild type="button" variant="ghost">
                    <Link href="/admin/pages">{t('Cancel')}</Link>
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? t('Saving…') : submitLabel}
                </Button>
            </div>
        </form>
    );
}
