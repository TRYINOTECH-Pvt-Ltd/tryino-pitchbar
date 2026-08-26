import { ChevronDown, ChevronRight, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import { PAGE_TEMPLATES, pageTemplateById } from './scenario-presets';
import type { PageContextPayload, WooContextPayload } from './scenario-presets';

type Props = {
    value: PageContextPayload;
    onChange: (next: PageContextPayload) => void;
};

export function PageContextForm({ value, onChange }: Props) {
    const { t } = useT();
    const [wpOpen, setWpOpen] = useState(
        () => value.source === 'wordpress' || value.post_id !== undefined,
    );
    const jsonLdString = useMemo(() => {
        if (value.json_ld === undefined || value.json_ld === null) {
            return '';
        }

        try {
            return JSON.stringify(value.json_ld, null, 2);
        } catch {
            return '';
        }
    }, [value.json_ld]);

    const set = (patch: Partial<PageContextPayload>) =>
        onChange({ ...value, ...patch });

    const setWoo = (patch: Partial<WooContextPayload>) => {
        const next: WooContextPayload = { ...(value.woo ?? {}), ...patch };

        // Strip empty strings so the sanitizer doesn't ship "" into
        // `woo.sku` etc. — keeps the payload clean.
        for (const [k, v] of Object.entries(next)) {
            if (typeof v === 'string' && v.trim() === '') {
                delete (next as Record<string, unknown>)[k];
            }
        }

        onChange({
            ...value,
            woo: Object.keys(next).length === 0 ? undefined : next,
        });
    };

    const csvFromArray = (arr: string[] | undefined): string =>
        Array.isArray(arr) ? arr.join(', ') : '';

    const arrayFromCsv = (raw: string): string[] | undefined => {
        const parts = raw
            .split(',')
            .map((s) => s.trim())
            .filter((s) => s !== '');

        return parts.length === 0 ? undefined : parts;
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-end gap-2">
                <div className="flex-1">
                    <Label className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        {t('Use template')}
                    </Label>
                    <Select
                        value=""
                        onValueChange={(id) => {
                            const tpl = pageTemplateById(id);
                            onChange(structuredClone(tpl.payload));
                        }}
                    >
                        <SelectTrigger>
                            <SelectValue
                                placeholder={t('Pick a starting page…')}
                            />
                        </SelectTrigger>
                        <SelectContent>
                            {PAGE_TEMPLATES.map((tpl) => (
                                <SelectItem key={tpl.id} value={tpl.id}>
                                    {tpl.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => onChange({})}
                    title={t('Clear page context')}
                >
                    <Trash2 className="h-3.5 w-3.5" />
                </Button>
            </div>

            <div className="flex flex-col gap-1.5">
                <Label
                    htmlFor="pg-url"
                    className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {t('URL')}
                </Label>
                <Input
                    id="pg-url"
                    value={value.url ?? ''}
                    placeholder="https://example.com/products/red-pen"
                    onChange={(e) => set({ url: e.target.value || undefined })}
                />
            </div>

            <div className="flex flex-col gap-1.5">
                <Label
                    htmlFor="pg-title"
                    className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {t('Title')}
                </Label>
                <Input
                    id="pg-title"
                    value={value.title ?? ''}
                    placeholder={t('Page title')}
                    onChange={(e) =>
                        set({ title: e.target.value || undefined })
                    }
                />
            </div>

            <div className="flex flex-col gap-1.5">
                <Label
                    htmlFor="pg-desc"
                    className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {t('Description')}
                </Label>
                <Textarea
                    id="pg-desc"
                    rows={2}
                    value={value.description ?? ''}
                    placeholder={t('Meta description')}
                    onChange={(e) =>
                        set({ description: e.target.value || undefined })
                    }
                />
            </div>

            <div className="flex flex-col gap-1.5">
                <Label className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    og:type
                </Label>
                <Select
                    value={String(value.og?.type ?? '__none__')}
                    onValueChange={(v) =>
                        set({
                            og: {
                                ...(value.og ?? {}),
                                ...(v === '__none__'
                                    ? { type: undefined }
                                    : { type: v }),
                            } as Record<string, string | number>,
                        })
                    }
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__none__">{t('Not set')}</SelectItem>
                        <SelectItem value="website">website</SelectItem>
                        <SelectItem value="article">article</SelectItem>
                        <SelectItem value="product">product</SelectItem>
                        <SelectItem value="book">book</SelectItem>
                        <SelectItem value="video.other">video.other</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div className="flex flex-col gap-1.5">
                <Label
                    htmlFor="pg-h1"
                    className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    H1
                </Label>
                <Input
                    id="pg-h1"
                    value={value.h1 ?? ''}
                    onChange={(e) => set({ h1: e.target.value || undefined })}
                />
            </div>

            <div className="flex flex-col gap-1.5">
                <Label
                    htmlFor="pg-jsonld"
                    className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {t('JSON-LD')}
                </Label>
                <Textarea
                    id="pg-jsonld"
                    rows={6}
                    className="font-mono text-xs"
                    value={jsonLdString}
                    placeholder='{"@type":"Product",...}'
                    onChange={(e) => {
                        const raw = e.target.value;

                        if (raw.trim() === '') {
                            set({ json_ld: undefined });

                            return;
                        }

                        try {
                            set({ json_ld: JSON.parse(raw) });
                        } catch {
                            // Invalid JSON during typing — keep the value
                            // staged as a string so the textarea reflects
                            // what the admin typed; the backend will sanitize.
                            set({ json_ld: raw });
                        }
                    }}
                />
            </div>

            <div className="flex flex-col gap-1.5">
                <Label
                    htmlFor="pg-vis"
                    className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {t('Visible body text')}
                </Label>
                <Textarea
                    id="pg-vis"
                    rows={4}
                    value={value.visible_text ?? ''}
                    placeholder={t('Body content the visitor would see')}
                    onChange={(e) =>
                        set({ visible_text: e.target.value || undefined })
                    }
                />
            </div>

            <section className="flex flex-col gap-3 rounded-md border bg-muted/20 p-3">
                <button
                    type="button"
                    onClick={() => setWpOpen((v) => !v)}
                    className="flex items-center justify-between text-xs font-semibold tracking-wide text-muted-foreground uppercase hover:text-foreground"
                >
                    <span className="flex items-center gap-1.5">
                        {wpOpen ? (
                            <ChevronDown className="h-3 w-3" />
                        ) : (
                            <ChevronRight className="h-3 w-3" />
                        )}
                        {t('WordPress / WooCommerce')}
                    </span>
                    {value.source === 'wordpress' && (
                        <span className="rounded bg-primary/15 px-1.5 py-0.5 text-[10px] font-medium tracking-normal text-primary normal-case">
                            {t('source: wordpress')}
                        </span>
                    )}
                </button>
                {wpOpen && (
                    <div className="flex flex-col gap-3">
                        <p className="text-xs leading-snug text-muted-foreground">
                            {t(
                                "Match the WP plugin's PageContext::collect() payload so the playground exercises the same retrieval and prompt path your live site will. Leave blank for non-WP sites.",
                            )}
                        </p>

                        <div className="flex flex-col gap-1.5">
                            <Label className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                {t('source')}
                            </Label>
                            <Select
                                value={value.source ?? '__none__'}
                                onValueChange={(v) =>
                                    set({
                                        source:
                                            v === 'wordpress'
                                                ? 'wordpress'
                                                : undefined,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">
                                        {t('Not set')}
                                    </SelectItem>
                                    <SelectItem value="wordpress">
                                        wordpress
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid grid-cols-2 gap-2">
                            <div className="flex flex-col gap-1.5">
                                <Label
                                    htmlFor="pg-postid"
                                    className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    post_id
                                </Label>
                                <Input
                                    id="pg-postid"
                                    type="number"
                                    min={1}
                                    value={
                                        value.post_id === undefined
                                            ? ''
                                            : String(value.post_id)
                                    }
                                    placeholder="142"
                                    onChange={(e) => {
                                        const raw = e.target.value;
                                        const parsed =
                                            raw === ''
                                                ? undefined
                                                : Number(raw);

                                        set({
                                            post_id:
                                                parsed !== undefined &&
                                                Number.isFinite(parsed) &&
                                                parsed > 0
                                                    ? Math.floor(parsed)
                                                    : undefined,
                                        });
                                    }}
                                />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label
                                    htmlFor="pg-posttype"
                                    className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    post_type
                                </Label>
                                <Input
                                    id="pg-posttype"
                                    value={value.post_type ?? ''}
                                    placeholder="post / page / product"
                                    onChange={(e) =>
                                        set({
                                            post_type:
                                                e.target.value || undefined,
                                        })
                                    }
                                />
                            </div>
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label
                                htmlFor="pg-permalink"
                                className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                permalink
                            </Label>
                            <Input
                                id="pg-permalink"
                                value={value.permalink ?? ''}
                                placeholder="https://shop.example/product/blue-tee"
                                onChange={(e) =>
                                    set({
                                        permalink: e.target.value || undefined,
                                    })
                                }
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-2">
                            <div className="flex flex-col gap-1.5">
                                <Label
                                    htmlFor="pg-cats"
                                    className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    {t('categories (comma-separated)')}
                                </Label>
                                <Input
                                    id="pg-cats"
                                    value={csvFromArray(value.categories)}
                                    placeholder="tees, summer"
                                    onChange={(e) =>
                                        set({
                                            categories: arrayFromCsv(
                                                e.target.value,
                                            ),
                                        })
                                    }
                                />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label
                                    htmlFor="pg-tags"
                                    className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    {t('tags (comma-separated)')}
                                </Label>
                                <Input
                                    id="pg-tags"
                                    value={csvFromArray(value.tags)}
                                    placeholder="canvas, summer"
                                    onChange={(e) =>
                                        set({
                                            tags: arrayFromCsv(e.target.value),
                                        })
                                    }
                                />
                            </div>
                        </div>

                        <div className="mt-2 flex flex-col gap-3 rounded-md border border-dashed bg-background/40 p-3">
                            <div className="flex items-baseline justify-between">
                                <Label className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                    {t('WooCommerce product fields')}
                                </Label>
                                {value.woo && (
                                    <button
                                        type="button"
                                        onClick={() => set({ woo: undefined })}
                                        className="text-[10px] text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                                    >
                                        {t('Clear')}
                                    </button>
                                )}
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="flex flex-col gap-1.5">
                                    <Label
                                        htmlFor="pg-woo-sku"
                                        className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                                    >
                                        sku
                                    </Label>
                                    <Input
                                        id="pg-woo-sku"
                                        value={value.woo?.sku ?? ''}
                                        placeholder="T-BLU-M"
                                        onChange={(e) =>
                                            setWoo({ sku: e.target.value })
                                        }
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label
                                        htmlFor="pg-woo-name"
                                        className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                                    >
                                        name
                                    </Label>
                                    <Input
                                        id="pg-woo-name"
                                        value={value.woo?.name ?? ''}
                                        placeholder="Blue tee"
                                        onChange={(e) =>
                                            setWoo({ name: e.target.value })
                                        }
                                    />
                                </div>
                            </div>
                            <div className="grid grid-cols-3 gap-2">
                                <div className="flex flex-col gap-1.5">
                                    <Label
                                        htmlFor="pg-woo-price"
                                        className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                                    >
                                        price
                                    </Label>
                                    <Input
                                        id="pg-woo-price"
                                        value={value.woo?.price ?? ''}
                                        placeholder="29.00"
                                        onChange={(e) =>
                                            setWoo({ price: e.target.value })
                                        }
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label
                                        htmlFor="pg-woo-cur"
                                        className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                                    >
                                        currency
                                    </Label>
                                    <Input
                                        id="pg-woo-cur"
                                        value={value.woo?.currency ?? ''}
                                        placeholder="USD"
                                        maxLength={3}
                                        onChange={(e) =>
                                            setWoo({
                                                currency:
                                                    e.target.value.toUpperCase(),
                                            })
                                        }
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label
                                        htmlFor="pg-woo-stock"
                                        className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                                    >
                                        stock_status
                                    </Label>
                                    <Select
                                        value={
                                            value.woo?.stock_status ??
                                            '__none__'
                                        }
                                        onValueChange={(v) =>
                                            setWoo({
                                                stock_status:
                                                    v === '__none__'
                                                        ? undefined
                                                        : v,
                                            })
                                        }
                                    >
                                        <SelectTrigger id="pg-woo-stock">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">
                                                {t('Not set')}
                                            </SelectItem>
                                            <SelectItem value="instock">
                                                instock
                                            </SelectItem>
                                            <SelectItem value="outofstock">
                                                outofstock
                                            </SelectItem>
                                            <SelectItem value="onbackorder">
                                                onbackorder
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </section>
        </div>
    );
}
