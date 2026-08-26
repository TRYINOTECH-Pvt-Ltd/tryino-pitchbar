import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { SortableList } from '@/components/sortable-list';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { update as updateSystemSettings } from '@/routes/settings/system';

export type MarketingLinkItem = {
    label: string;
    href: string;
};

export type MarketingIconCard = {
    icon: string;
    title: string;
    description: string;
};

export type MarketingStatItem = {
    icon: string;
    value: string;
    label: string;
};

export type MarketingSettingRow = {
    label: string;
    hint: string;
    value: string;
};

export type MarketingFooterGroup = {
    title: string;
    links: MarketingLinkItem[];
};

export type MarketingTestimonialItem = {
    name: string;
    role: string;
    company: string;
    quote: string;
    avatar?: string | null;
};

export type MarketingFaqItem = {
    question: string;
    answer: string;
};

export type MarketingContentFormValue = {
    brand_name: string;
    nav_items: MarketingLinkItem[];
    header: {
        resources_label: string;
        resources_href: string;
        docs_external_url?: string;
        show_documentation?: boolean;
        primary_button_label: string;
        primary_button_href: string;
    };
    hero: {
        badge: string;
        line_one: string;
        accent: string;
        line_two_suffix: string;
        line_three_prefix: string;
        line_three_highlight: string;
        line_four_highlight: string;
        description: string;
        site_test_label: string;
        site_test_placeholder: string;
        site_test_button_label: string;
        site_test_helper: string;
        live_demo_notice: string;
    };
    chat_preview: {
        title: string;
        badge: string;
        question: string;
        answer: string;
        plan_badge: string;
        plan_name: string;
        plan_price: string;
        plan_interval: string;
        plan_note: string;
        plan_features: string[];
        plan_button_label: string;
        typing_label: string;
        powered_by_prefix: string;
    };
    stats: MarketingStatItem[];
    video: {
        badge: string;
        title: string;
        description: string;
        bullets: string[];
        duration_label: string;
        tag_label: string;
        scene_label: string;
        card_title: string;
        timecode: string;
        chips: string[];
        footer_title: string;
        footer_description: string;
        button_label: string;
        href: string;
    };
    where_it_fits: {
        badge: string;
        title: string;
        cards: MarketingIconCard[];
    };
    feature_grid: {
        badge: string;
        title: string;
        cards: MarketingIconCard[];
    };
    control: {
        badge: string;
        title: string;
        description: string;
        callouts: MarketingIconCard[];
        settings_card_title: string;
        settings_rows: MarketingSettingRow[];
        cancel_label: string;
        save_button_label: string;
    };
    steps: {
        badge: string;
        title: string;
        items: MarketingIconCard[];
    };
    insights: {
        badge: string;
        chart_title: string;
        chart_description: string;
        metric_label: string;
        metric_value: string;
        metric_trend: string;
        chart_points: number[];
        chart_labels: string[];
        cards: MarketingIconCard[];
    };
    testimonials: {
        badge: string;
        title: string;
        kicker: string;
        items: MarketingTestimonialItem[];
    };
    faq: {
        badge: string;
        title: string;
        kicker: string;
        items: MarketingFaqItem[];
    };
    final_cta: {
        title: string;
        description: string;
        primary_button_label: string;
        primary_button_href: string;
        secondary_button_label: string;
        secondary_button_href: string;
    };
    footer: {
        brand_description: string;
        socials: MarketingLinkItem[];
        groups: MarketingFooterGroup[];
        legal_title: string;
        legal_links: MarketingLinkItem[];
        copyright: string;
    };
};

type Props = {
    initial: MarketingContentFormValue;
};

type MarketingTabKey =
    | 'brand'
    | 'hero'
    | 'preview'
    | 'features'
    | 'closing'
    | 'footer';

function useMarketingTabs(): Array<{
    key: MarketingTabKey;
    label: string;
    description: string;
}> {
    const { t } = useT();

    return [
        {
            key: 'brand',
            label: t('Brand & nav'),
            description: t(
                'Header identity, resources link, and navigation items.',
            ),
        },
        {
            key: 'hero',
            label: t('Hero'),
            description: t(
                'Main headline, site-test form copy, and live demo notice.',
            ),
        },
        {
            key: 'preview',
            label: t('Preview & video'),
            description: t(
                'Chat preview card, headline stats, and walkthrough block.',
            ),
        },
        {
            key: 'features',
            label: t('Feature sections'),
            description: t(
                'Where-it-fits, feature grid, control area, and setup steps.',
            ),
        },
        {
            key: 'closing',
            label: t('Closing sections'),
            description: t(
                'Insights, testimonials, FAQ, and the final call to action.',
            ),
        },
        {
            key: 'footer',
            label: t('Footer'),
            description: t(
                'Footer description, socials, grouped links, legal links, and copyright.',
            ),
        },
    ];
}

function cloneContent(
    content: MarketingContentFormValue,
): MarketingContentFormValue {
    return JSON.parse(JSON.stringify(content)) as MarketingContentFormValue;
}

function setNestedValue(
    source: MarketingContentFormValue,
    path: Array<string | number>,
    value: unknown,
): MarketingContentFormValue {
    const draft = cloneContent(source) as MarketingContentFormValue &
        Record<string | number, unknown>;
    let cursor: Record<string | number, unknown> = draft;

    for (let index = 0; index < path.length - 1; index++) {
        cursor = cursor[path[index]] as Record<string | number, unknown>;
    }

    cursor[path[path.length - 1]] = value;

    return draft;
}

function updateNestedList(
    source: MarketingContentFormValue,
    path: Array<string | number>,
    updater: (items: unknown[]) => unknown[],
): MarketingContentFormValue {
    const draft = cloneContent(source) as MarketingContentFormValue &
        Record<string | number, unknown>;
    let cursor: Record<string | number, unknown> = draft;

    for (let index = 0; index < path.length - 1; index++) {
        cursor = cursor[path[index]] as Record<string | number, unknown>;
    }

    const key = path[path.length - 1];
    const current = Array.isArray(cursor[key])
        ? (cursor[key] as unknown[])
        : [];
    cursor[key] = updater(current);

    return draft;
}

function stringListToText(values: string[]): string {
    return values.join('\n');
}

function textToStringList(value: string): string[] {
    return value
        .split('\n')
        .map((item) => item.trim())
        .filter((item) => item.length > 0);
}

function numberListToText(values: number[]): string {
    return values.join(', ');
}

function textToNumberList(value: string): number[] {
    return value
        .split(/[\n,]+/)
        .map((item) => Number(item.trim()))
        .filter((item) => Number.isFinite(item));
}

function blankLink(): MarketingLinkItem {
    return { label: '', href: '' };
}

function blankIconCard(): MarketingIconCard {
    return { icon: 'Sparkles', title: '', description: '' };
}

function blankStat(): MarketingStatItem {
    return { icon: 'Sparkles', value: '', label: '' };
}

function blankSettingRow(): MarketingSettingRow {
    return { label: '', hint: '', value: '' };
}

function blankTestimonial(): MarketingTestimonialItem {
    return { name: '', role: '', company: '', quote: '', avatar: null };
}

function blankFaq(): MarketingFaqItem {
    return { question: '', answer: '' };
}

function blankFooterGroup(): MarketingFooterGroup {
    return { title: '', links: [blankLink()] };
}

function EditorSection({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <Card className="border-border/80 p-5">
            <div className="space-y-1">
                <h3 className="font-semibold">{title}</h3>
                <p className="text-xs text-muted-foreground">{description}</p>
            </div>
            <div className="mt-4 space-y-4">{children}</div>
        </Card>
    );
}

function FieldRow({ children }: { children: ReactNode }) {
    return <div className="grid gap-4 md:grid-cols-2">{children}</div>;
}

function StringListField({
    id,
    label,
    value,
    onChange,
    help,
}: {
    id: string;
    label: string;
    value: string[];
    onChange: (value: string[]) => void;
    help?: string;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Textarea
                id={id}
                value={stringListToText(value)}
                onChange={(event) =>
                    onChange(textToStringList(event.target.value))
                }
                className="min-h-28"
            />
            {help && <p className="text-xs text-muted-foreground">{help}</p>}
        </div>
    );
}

function NumberListField({
    id,
    label,
    value,
    onChange,
    help,
}: {
    id: string;
    label: string;
    value: number[];
    onChange: (value: number[]) => void;
    help?: string;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Textarea
                id={id}
                value={numberListToText(value)}
                onChange={(event) =>
                    onChange(textToNumberList(event.target.value))
                }
                className="min-h-24"
            />
            {help && <p className="text-xs text-muted-foreground">{help}</p>}
        </div>
    );
}

function LinkListEditor({
    title,
    items,
    onChange,
    addLabel,
}: {
    title: string;
    items: MarketingLinkItem[];
    onChange: (items: MarketingLinkItem[]) => void;
    addLabel?: string;
}) {
    const { t } = useT();

    // Each row's id is its current position in the array. Stable
    // mid-drag (we don't mutate items until onReorder fires) and
    // automatically resets after the parent saves the new order.
    const keyed = items.map((item, index) => ({ item, key: String(index) }));

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-medium">{title}</p>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => onChange([...items, blankLink()])}
                >
                    <Plus className="size-3.5" />
                    {addLabel ?? t('Add link')}
                </Button>
            </div>

            {items.length === 0 && (
                <p className="rounded-md border border-dashed border-border/70 px-3 py-4 text-center text-xs text-muted-foreground">
                    {t(
                        'No links — this section will be hidden on the public site until you add one.',
                    )}
                </p>
            )}

            {items.length > 0 && (
                <SortableList
                    items={keyed}
                    keyOf={(entry) => entry.key}
                    onReorder={(orderedIds) => {
                        const next = orderedIds
                            .map((id) => items[Number(id)])
                            .filter((it): it is MarketingLinkItem =>
                                Boolean(it),
                            );
                        onChange(next);
                    }}
                >
                    {(entry) => {
                        const index = Number(entry.key);

                        return (
                            <Card className="border-border/70 p-4">
                                <div className="grid gap-3 md:grid-cols-[1fr_1fr_auto] md:items-end">
                                    <div className="grid gap-1">
                                        <Label
                                            htmlFor={`${title}-label-${index}`}
                                        >
                                            {t('Label')}
                                        </Label>
                                        <Input
                                            id={`${title}-label-${index}`}
                                            value={entry.item.label}
                                            onChange={(event) =>
                                                onChange(
                                                    items.map(
                                                        (
                                                            current,
                                                            currentIndex,
                                                        ) =>
                                                            currentIndex ===
                                                            index
                                                                ? {
                                                                      ...current,
                                                                      label: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : current,
                                                    ),
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label
                                            htmlFor={`${title}-href-${index}`}
                                        >
                                            {t('Href')}
                                        </Label>
                                        <Input
                                            id={`${title}-href-${index}`}
                                            value={entry.item.href}
                                            onChange={(event) =>
                                                onChange(
                                                    items.map(
                                                        (
                                                            current,
                                                            currentIndex,
                                                        ) =>
                                                            currentIndex ===
                                                            index
                                                                ? {
                                                                      ...current,
                                                                      href: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : current,
                                                    ),
                                                )
                                            }
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            onChange(
                                                items.filter(
                                                    (_, currentIndex) =>
                                                        currentIndex !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 className="size-3.5" />
                                        {t('Remove')}
                                    </Button>
                                </div>
                            </Card>
                        );
                    }}
                </SortableList>
            )}
        </div>
    );
}

function IconCardListEditor({
    title,
    items,
    onChange,
    addLabel,
}: {
    title: string;
    items: MarketingIconCard[];
    onChange: (items: MarketingIconCard[]) => void;
    addLabel: string;
}) {
    const { t } = useT();

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-medium">{title}</p>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => onChange([...items, blankIconCard()])}
                >
                    <Plus className="size-3.5" />
                    {addLabel}
                </Button>
            </div>

            {items.map((item, index) => (
                <Card
                    key={`${title}-${index}`}
                    className="border-border/70 p-4"
                >
                    <div className="grid gap-3 md:grid-cols-[160px_1fr_auto] md:items-end">
                        <div className="grid gap-1">
                            <Label htmlFor={`${title}-icon-${index}`}>
                                {t('Icon')}
                            </Label>
                            <Input
                                id={`${title}-icon-${index}`}
                                value={item.icon}
                                onChange={(event) =>
                                    onChange(
                                        items.map((current, currentIndex) =>
                                            currentIndex === index
                                                ? {
                                                      ...current,
                                                      icon: event.target.value,
                                                  }
                                                : current,
                                        ),
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-3">
                            <div className="grid gap-1">
                                <Label htmlFor={`${title}-title-${index}`}>
                                    {t('Title')}
                                </Label>
                                <Input
                                    id={`${title}-title-${index}`}
                                    value={item.title}
                                    onChange={(event) =>
                                        onChange(
                                            items.map(
                                                (current, currentIndex) =>
                                                    currentIndex === index
                                                        ? {
                                                              ...current,
                                                              title: event
                                                                  .target.value,
                                                          }
                                                        : current,
                                            ),
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-1">
                                <Label
                                    htmlFor={`${title}-description-${index}`}
                                >
                                    {t('Description')}
                                </Label>
                                <Textarea
                                    id={`${title}-description-${index}`}
                                    value={item.description}
                                    onChange={(event) =>
                                        onChange(
                                            items.map(
                                                (current, currentIndex) =>
                                                    currentIndex === index
                                                        ? {
                                                              ...current,
                                                              description:
                                                                  event.target
                                                                      .value,
                                                          }
                                                        : current,
                                            ),
                                        )
                                    }
                                />
                            </div>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                onChange(
                                    items.filter(
                                        (_, currentIndex) =>
                                            currentIndex !== index,
                                    ),
                                )
                            }
                            disabled={items.length === 1}
                        >
                            <Trash2 className="size-3.5" />
                            {t('Remove')}
                        </Button>
                    </div>
                </Card>
            ))}
        </div>
    );
}

function TestimonialListEditor({
    items,
    onChange,
}: {
    items: MarketingTestimonialItem[];
    onChange: (items: MarketingTestimonialItem[]) => void;
}) {
    const { t } = useT();

    const update = (
        index: number,
        patch: Partial<MarketingTestimonialItem>,
    ): void => {
        onChange(
            items.map((current, currentIndex) =>
                currentIndex === index ? { ...current, ...patch } : current,
            ),
        );
    };

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-medium">{t('Quotes')}</p>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => onChange([...items, blankTestimonial()])}
                >
                    <Plus className="size-3.5" />
                    {t('Add testimonial')}
                </Button>
            </div>

            {items.map((item, index) => (
                <Card
                    key={`testimonial-${index}`}
                    className="space-y-3 border-border/70 p-4"
                >
                    <div className="grid gap-3 md:grid-cols-3">
                        <div className="grid gap-1">
                            <Label htmlFor={`testimonial-name-${index}`}>
                                {t('Name')}
                            </Label>
                            <Input
                                id={`testimonial-name-${index}`}
                                value={item.name}
                                onChange={(event) =>
                                    update(index, { name: event.target.value })
                                }
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor={`testimonial-role-${index}`}>
                                {t('Role')}
                            </Label>
                            <Input
                                id={`testimonial-role-${index}`}
                                value={item.role}
                                onChange={(event) =>
                                    update(index, { role: event.target.value })
                                }
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor={`testimonial-company-${index}`}>
                                {t('Company')}
                            </Label>
                            <Input
                                id={`testimonial-company-${index}`}
                                value={item.company}
                                onChange={(event) =>
                                    update(index, {
                                        company: event.target.value,
                                    })
                                }
                            />
                        </div>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor={`testimonial-quote-${index}`}>
                            {t('Quote')}
                        </Label>
                        <Textarea
                            id={`testimonial-quote-${index}`}
                            value={item.quote}
                            rows={3}
                            onChange={(event) =>
                                update(index, { quote: event.target.value })
                            }
                        />
                    </div>
                    <div className="grid gap-3 md:grid-cols-[1fr_auto] md:items-end">
                        <div className="grid gap-1">
                            <Label htmlFor={`testimonial-avatar-${index}`}>
                                {t('Avatar URL (optional)')}
                            </Label>
                            <Input
                                id={`testimonial-avatar-${index}`}
                                value={item.avatar ?? ''}
                                onChange={(event) =>
                                    update(index, {
                                        avatar:
                                            event.target.value === ''
                                                ? null
                                                : event.target.value,
                                    })
                                }
                                placeholder="https://…/face.jpg"
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                onChange(
                                    items.filter(
                                        (_, currentIndex) =>
                                            currentIndex !== index,
                                    ),
                                )
                            }
                        >
                            <Trash2 className="size-3.5" />
                            {t('Remove')}
                        </Button>
                    </div>
                </Card>
            ))}
        </div>
    );
}

function FaqListEditor({
    items,
    onChange,
}: {
    items: MarketingFaqItem[];
    onChange: (items: MarketingFaqItem[]) => void;
}) {
    const { t } = useT();

    const update = (index: number, patch: Partial<MarketingFaqItem>): void => {
        onChange(
            items.map((current, currentIndex) =>
                currentIndex === index ? { ...current, ...patch } : current,
            ),
        );
    };

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-medium">{t('Q&A items')}</p>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => onChange([...items, blankFaq()])}
                >
                    <Plus className="size-3.5" />
                    {t('Add FAQ')}
                </Button>
            </div>

            {items.map((item, index) => (
                <Card
                    key={`faq-${index}`}
                    className="space-y-3 border-border/70 p-4"
                >
                    <div className="grid gap-1">
                        <Label htmlFor={`faq-question-${index}`}>
                            {t('Question')}
                        </Label>
                        <Input
                            id={`faq-question-${index}`}
                            value={item.question}
                            onChange={(event) =>
                                update(index, { question: event.target.value })
                            }
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor={`faq-answer-${index}`}>
                            {t('Answer')}
                        </Label>
                        <Textarea
                            id={`faq-answer-${index}`}
                            value={item.answer}
                            rows={3}
                            onChange={(event) =>
                                update(index, { answer: event.target.value })
                            }
                        />
                    </div>
                    <div className="flex justify-end">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                onChange(
                                    items.filter(
                                        (_, currentIndex) =>
                                            currentIndex !== index,
                                    ),
                                )
                            }
                        >
                            <Trash2 className="size-3.5" />
                            {t('Remove')}
                        </Button>
                    </div>
                </Card>
            ))}
        </div>
    );
}

function StatsEditor({
    items,
    onChange,
}: {
    items: MarketingStatItem[];
    onChange: (items: MarketingStatItem[]) => void;
}) {
    const { t } = useT();

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-medium">{t('Stats')}</p>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => onChange([...items, blankStat()])}
                >
                    <Plus className="size-3.5" />
                    {t('Add stat')}
                </Button>
            </div>

            {items.map((item, index) => (
                <Card key={`stat-${index}`} className="border-border/70 p-4">
                    <div className="grid gap-3 md:grid-cols-[140px_120px_1fr_auto] md:items-end">
                        <div className="grid gap-1">
                            <Label htmlFor={`stat-icon-${index}`}>
                                {t('Icon')}
                            </Label>
                            <Input
                                id={`stat-icon-${index}`}
                                value={item.icon}
                                onChange={(event) =>
                                    onChange(
                                        items.map((current, currentIndex) =>
                                            currentIndex === index
                                                ? {
                                                      ...current,
                                                      icon: event.target.value,
                                                  }
                                                : current,
                                        ),
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor={`stat-value-${index}`}>
                                {t('Value')}
                            </Label>
                            <Input
                                id={`stat-value-${index}`}
                                value={item.value}
                                onChange={(event) =>
                                    onChange(
                                        items.map((current, currentIndex) =>
                                            currentIndex === index
                                                ? {
                                                      ...current,
                                                      value: event.target.value,
                                                  }
                                                : current,
                                        ),
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor={`stat-label-${index}`}>
                                {t('Label')}
                            </Label>
                            <Input
                                id={`stat-label-${index}`}
                                value={item.label}
                                onChange={(event) =>
                                    onChange(
                                        items.map((current, currentIndex) =>
                                            currentIndex === index
                                                ? {
                                                      ...current,
                                                      label: event.target.value,
                                                  }
                                                : current,
                                        ),
                                    )
                                }
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                onChange(
                                    items.filter(
                                        (_, currentIndex) =>
                                            currentIndex !== index,
                                    ),
                                )
                            }
                            disabled={items.length === 1}
                        >
                            <Trash2 className="size-3.5" />
                            {t('Remove')}
                        </Button>
                    </div>
                </Card>
            ))}
        </div>
    );
}

function SettingRowsEditor({
    rows,
    onChange,
}: {
    rows: MarketingSettingRow[];
    onChange: (rows: MarketingSettingRow[]) => void;
}) {
    const { t } = useT();

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-medium">{t('Settings rows')}</p>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => onChange([...rows, blankSettingRow()])}
                >
                    <Plus className="size-3.5" />
                    {t('Add row')}
                </Button>
            </div>

            {rows.map((row, index) => (
                <Card
                    key={`setting-row-${index}`}
                    className="border-border/70 p-4"
                >
                    <div className="grid gap-3 md:grid-cols-[1fr_1fr_auto] md:items-end">
                        <div className="grid gap-1">
                            <Label htmlFor={`setting-row-label-${index}`}>
                                {t('Label')}
                            </Label>
                            <Input
                                id={`setting-row-label-${index}`}
                                value={row.label}
                                onChange={(event) =>
                                    onChange(
                                        rows.map((current, currentIndex) =>
                                            currentIndex === index
                                                ? {
                                                      ...current,
                                                      label: event.target.value,
                                                  }
                                                : current,
                                        ),
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor={`setting-row-hint-${index}`}>
                                {t('Hint')}
                            </Label>
                            <Input
                                id={`setting-row-hint-${index}`}
                                value={row.hint}
                                onChange={(event) =>
                                    onChange(
                                        rows.map((current, currentIndex) =>
                                            currentIndex === index
                                                ? {
                                                      ...current,
                                                      hint: event.target.value,
                                                  }
                                                : current,
                                        ),
                                    )
                                }
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                onChange(
                                    rows.filter(
                                        (_, currentIndex) =>
                                            currentIndex !== index,
                                    ),
                                )
                            }
                            disabled={rows.length === 1}
                        >
                            <Trash2 className="size-3.5" />
                            {t('Remove')}
                        </Button>
                    </div>
                    <div className="mt-3 grid gap-1">
                        <Label htmlFor={`setting-row-value-${index}`}>
                            {t('Value')}
                        </Label>
                        <Textarea
                            id={`setting-row-value-${index}`}
                            value={row.value}
                            onChange={(event) =>
                                onChange(
                                    rows.map((current, currentIndex) =>
                                        currentIndex === index
                                            ? {
                                                  ...current,
                                                  value: event.target.value,
                                              }
                                            : current,
                                    ),
                                )
                            }
                        />
                    </div>
                </Card>
            ))}
        </div>
    );
}

function FooterGroupsEditor({
    groups,
    onChange,
}: {
    groups: MarketingFooterGroup[];
    onChange: (groups: MarketingFooterGroup[]) => void;
}) {
    const { t } = useT();

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-medium">{t('Footer groups')}</p>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => onChange([...groups, blankFooterGroup()])}
                >
                    <Plus className="size-3.5" />
                    {t('Add group')}
                </Button>
            </div>

            {groups.map((group, index) => (
                <Card
                    key={`footer-group-${index}`}
                    className="border-border/70 p-4"
                >
                    <div className="grid gap-3 md:grid-cols-[1fr_auto] md:items-end">
                        <div className="grid gap-1">
                            <Label htmlFor={`footer-group-title-${index}`}>
                                {t('Group title')}
                            </Label>
                            <Input
                                id={`footer-group-title-${index}`}
                                value={group.title}
                                onChange={(event) =>
                                    onChange(
                                        groups.map((current, currentIndex) =>
                                            currentIndex === index
                                                ? {
                                                      ...current,
                                                      title: event.target.value,
                                                  }
                                                : current,
                                        ),
                                    )
                                }
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                onChange(
                                    groups.filter(
                                        (_, currentIndex) =>
                                            currentIndex !== index,
                                    ),
                                )
                            }
                            disabled={groups.length === 1}
                        >
                            <Trash2 className="size-3.5" />
                            {t('Remove')}
                        </Button>
                    </div>

                    <div className="mt-4">
                        <LinkListEditor
                            title={t('Links')}
                            items={group.links}
                            onChange={(links) =>
                                onChange(
                                    groups.map((current, currentIndex) =>
                                        currentIndex === index
                                            ? { ...current, links }
                                            : current,
                                    ),
                                )
                            }
                            addLabel={t('Add group link')}
                        />
                    </div>
                </Card>
            ))}
        </div>
    );
}

export default function MarketingContentEditor({ initial }: Props) {
    const { t } = useT();
    const MARKETING_TABS = useMarketingTabs();
    const form = useForm<{
        marketing_home_content: MarketingContentFormValue;
    }>({
        marketing_home_content: initial,
    });

    const [activeTab, setActiveTab] = useState<MarketingTabKey>('brand');

    const content = form.data.marketing_home_content;
    const activeTabMeta =
        MARKETING_TABS.find((tab) => tab.key === activeTab) ??
        MARKETING_TABS[0];

    const setField = (path: Array<string | number>, value: unknown) => {
        form.setData(
            'marketing_home_content',
            setNestedValue(content, path, value),
        );
    };

    const setList = (path: Array<string | number>, items: unknown[]) => {
        form.setData(
            'marketing_home_content',
            updateNestedList(content, path, () => items),
        );
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.patch(updateSystemSettings.url('marketing'), {
            preserveScroll: true,
        });
    };

    const switchTab = (tab: MarketingTabKey) => {
        setActiveTab(tab);
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="space-y-3">
                <div className="overflow-x-auto pb-1">
                    <div className="inline-flex min-w-full gap-2 rounded-2xl border border-border/80 bg-muted/15 p-1.5">
                        {MARKETING_TABS.map((tab) => {
                            const isActive = tab.key === activeTab;

                            return (
                                <button
                                    key={tab.key}
                                    type="button"
                                    onClick={() => switchTab(tab.key)}
                                    className={cn(
                                        'rounded-xl px-4 py-2 text-sm font-medium whitespace-nowrap transition',
                                        isActive
                                            ? 'bg-background text-foreground shadow-sm ring-1 ring-border/80'
                                            : 'text-muted-foreground hover:bg-background/70 hover:text-foreground',
                                    )}
                                >
                                    {tab.label}
                                </button>
                            );
                        })}
                    </div>
                </div>

                <div className="rounded-2xl border border-border/80 bg-muted/15 px-4 py-3">
                    <p className="text-[11px] font-semibold tracking-[0.16em] text-muted-foreground uppercase">
                        {t('Editing tab')}
                    </p>
                    <h3 className="mt-1 text-sm font-semibold">
                        {activeTabMeta.label}
                    </h3>
                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                        {activeTabMeta.description}
                    </p>
                </div>
            </div>

            {activeTab === 'brand' && (
                <EditorSection
                    title={t('Brand and navigation')}
                    description={t(
                        'Manage the header brand name, resources link, and the navigation items shown across the landing page.',
                    )}
                >
                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="marketing-brand-name">
                                {t('Brand name')}
                            </Label>
                            <Input
                                id="marketing-brand-name"
                                value={content.brand_name}
                                onChange={(event) =>
                                    setField(['brand_name'], event.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="marketing-header-resource-label">
                                {t('Header resources label')}
                            </Label>
                            <Input
                                id="marketing-header-resource-label"
                                value={content.header.resources_label}
                                onChange={(event) =>
                                    setField(
                                        ['header', 'resources_label'],
                                        event.target.value,
                                    )
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'Leave empty to hide this link from the top navigation.',
                                )}
                            </p>
                        </div>
                    </FieldRow>

                    <FieldRow>
                        <div className="grid gap-1.5 md:col-span-2">
                            <Label htmlFor="marketing-header-resource-href">
                                {t('Header resources href')}
                            </Label>
                            <Input
                                id="marketing-header-resource-href"
                                value={content.header.resources_href}
                                onChange={(event) =>
                                    setField(
                                        ['header', 'resources_href'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>

                    <FieldRow>
                        <div className="grid gap-1.5 md:col-span-2">
                            <Label htmlFor="marketing-header-docs-external">
                                {t('External documentation URL')}
                            </Label>
                            <Input
                                id="marketing-header-docs-external"
                                placeholder="https://docs.yoursite.com"
                                value={content.header.docs_external_url ?? ''}
                                onChange={(event) =>
                                    setField(
                                        ['header', 'docs_external_url'],
                                        event.target.value,
                                    )
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'Point all built-in documentation links (top nav + footer) at your own docs site. Leave empty to use the built-in /documentation.',
                                )}
                            </p>
                        </div>
                    </FieldRow>

                    <label className="flex items-start gap-3 rounded-md border border-border p-3">
                        <Checkbox
                            id="header-show-documentation"
                            checked={content.header.show_documentation === true}
                            onCheckedChange={(checked) =>
                                setField(
                                    ['header', 'show_documentation'],
                                    checked === true,
                                )
                            }
                            className="mt-0.5"
                        />
                        <span className="grid gap-0.5">
                            <span className="text-sm font-medium">
                                {t('Show the documentation link in the header')}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {t(
                                    'Adds the resources link (above) to the public navigation. Off by default; turn it on to expose your documentation to visitors.',
                                )}
                            </span>
                        </span>
                    </label>

                    <div className="rounded-md border border-border/70 bg-muted/20 px-3 py-2 text-xs text-muted-foreground">
                        {t(
                            'The top-right header CTA is now handled automatically and follows the current auth state, so it is no longer edited here.',
                        )}
                    </div>

                    <LinkListEditor
                        title={t('Header navigation')}
                        items={content.nav_items}
                        onChange={(items) => setList(['nav_items'], items)}
                        addLabel={t('Add nav item')}
                    />
                </EditorSection>
            )}

            {activeTab === 'hero' && (
                <EditorSection
                    title={t('Hero')}
                    description={t(
                        'Update the hero copy, badge, and the embedded site test prompt.',
                    )}
                >
                    <FieldRow>
                        <div className="grid gap-1.5 md:col-span-2">
                            <Label htmlFor="marketing-hero-badge">
                                {t('Badge')}
                            </Label>
                            <Input
                                id="marketing-hero-badge"
                                value={content.hero.badge}
                                onChange={(event) =>
                                    setField(
                                        ['hero', 'badge'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>
                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="marketing-hero-line-one">
                                {t('Line one')}
                            </Label>
                            <Input
                                id="marketing-hero-line-one"
                                value={content.hero.line_one}
                                onChange={(event) =>
                                    setField(
                                        ['hero', 'line_one'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="marketing-hero-accent">
                                {t('Accent word')}
                            </Label>
                            <Input
                                id="marketing-hero-accent"
                                value={content.hero.accent}
                                onChange={(event) =>
                                    setField(
                                        ['hero', 'accent'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>
                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="marketing-hero-line-two">
                                {t('Line two suffix')}
                            </Label>
                            <Input
                                id="marketing-hero-line-two"
                                value={content.hero.line_two_suffix}
                                onChange={(event) =>
                                    setField(
                                        ['hero', 'line_two_suffix'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="marketing-hero-line-three-prefix">
                                {t('Line three prefix')}
                            </Label>
                            <Input
                                id="marketing-hero-line-three-prefix"
                                value={content.hero.line_three_prefix}
                                onChange={(event) =>
                                    setField(
                                        ['hero', 'line_three_prefix'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>
                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="marketing-hero-line-three-highlight">
                                {t('Line three highlight')}
                            </Label>
                            <Input
                                id="marketing-hero-line-three-highlight"
                                value={content.hero.line_three_highlight}
                                onChange={(event) =>
                                    setField(
                                        ['hero', 'line_three_highlight'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="marketing-hero-line-four-highlight">
                                {t('Line four highlight')}
                            </Label>
                            <Input
                                id="marketing-hero-line-four-highlight"
                                value={content.hero.line_four_highlight}
                                onChange={(event) =>
                                    setField(
                                        ['hero', 'line_four_highlight'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>
                    <div className="grid gap-1.5">
                        <Label htmlFor="marketing-hero-description">
                            {t('Description')}
                        </Label>
                        <Textarea
                            id="marketing-hero-description"
                            value={content.hero.description}
                            onChange={(event) =>
                                setField(
                                    ['hero', 'description'],
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="marketing-site-test-label">
                                {t('Site test label')}
                            </Label>
                            <Input
                                id="marketing-site-test-label"
                                value={content.hero.site_test_label}
                                onChange={(event) =>
                                    setField(
                                        ['hero', 'site_test_label'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="marketing-site-test-button">
                                {t('Site test button')}
                            </Label>
                            <Input
                                id="marketing-site-test-button"
                                value={content.hero.site_test_button_label}
                                onChange={(event) =>
                                    setField(
                                        ['hero', 'site_test_button_label'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>
                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="marketing-site-test-placeholder">
                                Site test placeholder
                            </Label>
                            <Input
                                id="marketing-site-test-placeholder"
                                value={content.hero.site_test_placeholder}
                                onChange={(event) =>
                                    setField(
                                        ['hero', 'site_test_placeholder'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="marketing-site-test-helper">
                                Site test helper
                            </Label>
                            <Input
                                id="marketing-site-test-helper"
                                value={content.hero.site_test_helper}
                                onChange={(event) =>
                                    setField(
                                        ['hero', 'site_test_helper'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>
                    <div className="grid gap-1.5">
                        <Label htmlFor="marketing-live-demo-notice">
                            {t('Live demo notice')}
                        </Label>
                        <Input
                            id="marketing-live-demo-notice"
                            value={content.hero.live_demo_notice}
                            onChange={(event) =>
                                setField(
                                    ['hero', 'live_demo_notice'],
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                </EditorSection>
            )}

            {activeTab === 'preview' && (
                <>
                    <EditorSection
                        title={t('Chat preview and stats')}
                        description={t(
                            'Manage the interactive preview card and the headline stat row below the hero.',
                        )}
                    >
                        <FieldRow>
                            <div className="grid gap-1.5">
                                <Label htmlFor="chat-title">
                                    {t('Preview title')}
                                </Label>
                                <Input
                                    id="chat-title"
                                    value={content.chat_preview.title}
                                    onChange={(event) =>
                                        setField(
                                            ['chat_preview', 'title'],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="chat-badge">
                                    {t('Preview badge')}
                                </Label>
                                <Input
                                    id="chat-badge"
                                    value={content.chat_preview.badge}
                                    onChange={(event) =>
                                        setField(
                                            ['chat_preview', 'badge'],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="chat-question">
                                {t('Question bubble')}
                            </Label>
                            <Textarea
                                id="chat-question"
                                value={content.chat_preview.question}
                                onChange={(event) =>
                                    setField(
                                        ['chat_preview', 'question'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="chat-answer">
                                {t('Answer bubble')}
                            </Label>
                            <Textarea
                                id="chat-answer"
                                value={content.chat_preview.answer}
                                onChange={(event) =>
                                    setField(
                                        ['chat_preview', 'answer'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <FieldRow>
                            <div className="grid gap-1.5">
                                <Label htmlFor="chat-plan-badge">
                                    {t('Plan badge')}
                                </Label>
                                <Input
                                    id="chat-plan-badge"
                                    value={content.chat_preview.plan_badge}
                                    onChange={(event) =>
                                        setField(
                                            ['chat_preview', 'plan_badge'],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="chat-plan-name">
                                    {t('Plan name')}
                                </Label>
                                <Input
                                    id="chat-plan-name"
                                    value={content.chat_preview.plan_name}
                                    onChange={(event) =>
                                        setField(
                                            ['chat_preview', 'plan_name'],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </FieldRow>
                        <FieldRow>
                            <div className="grid gap-1.5">
                                <Label htmlFor="chat-plan-price">
                                    {t('Plan price')}
                                </Label>
                                <Input
                                    id="chat-plan-price"
                                    value={content.chat_preview.plan_price}
                                    onChange={(event) =>
                                        setField(
                                            ['chat_preview', 'plan_price'],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="chat-plan-interval">
                                    {t('Plan interval')}
                                </Label>
                                <Input
                                    id="chat-plan-interval"
                                    value={content.chat_preview.plan_interval}
                                    onChange={(event) =>
                                        setField(
                                            ['chat_preview', 'plan_interval'],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="chat-plan-note">
                                {t('Plan note')}
                            </Label>
                            <Textarea
                                id="chat-plan-note"
                                value={content.chat_preview.plan_note}
                                onChange={(event) =>
                                    setField(
                                        ['chat_preview', 'plan_note'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <StringListField
                            id="chat-plan-features"
                            label="Plan features"
                            value={content.chat_preview.plan_features}
                            onChange={(value) =>
                                setField(
                                    ['chat_preview', 'plan_features'],
                                    value,
                                )
                            }
                        />
                        <FieldRow>
                            <div className="grid gap-1.5">
                                <Label htmlFor="chat-plan-button">
                                    {t('Plan button')}
                                </Label>
                                <Input
                                    id="chat-plan-button"
                                    value={
                                        content.chat_preview.plan_button_label
                                    }
                                    onChange={(event) =>
                                        setField(
                                            [
                                                'chat_preview',
                                                'plan_button_label',
                                            ],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="chat-typing-label">
                                    {t('Typing label')}
                                </Label>
                                <Input
                                    id="chat-typing-label"
                                    value={content.chat_preview.typing_label}
                                    onChange={(event) =>
                                        setField(
                                            ['chat_preview', 'typing_label'],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="chat-powered-by-prefix">
                                {t('Powered by prefix')}
                            </Label>
                            <Input
                                id="chat-powered-by-prefix"
                                value={content.chat_preview.powered_by_prefix}
                                onChange={(event) =>
                                    setField(
                                        ['chat_preview', 'powered_by_prefix'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>

                        <StatsEditor
                            items={content.stats}
                            onChange={(items) => setList(['stats'], items)}
                        />
                    </EditorSection>

                    <EditorSection
                        title={t('Video walkthrough')}
                        description={t(
                            'Edit the video teaser copy and the labels around its preview card.',
                        )}
                    >
                        <FieldRow>
                            <div className="grid gap-1.5">
                                <Label htmlFor="video-badge">
                                    {t('Badge')}
                                </Label>
                                <Input
                                    id="video-badge"
                                    value={content.video.badge}
                                    onChange={(event) =>
                                        setField(
                                            ['video', 'badge'],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="video-href">
                                    {t('Video href')}
                                </Label>
                                <Input
                                    id="video-href"
                                    value={content.video.href}
                                    onChange={(event) =>
                                        setField(
                                            ['video', 'href'],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="video-title">{t('Title')}</Label>
                            <Input
                                id="video-title"
                                value={content.video.title}
                                onChange={(event) =>
                                    setField(
                                        ['video', 'title'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="video-description">
                                {t('Description')}
                            </Label>
                            <Textarea
                                id="video-description"
                                value={content.video.description}
                                onChange={(event) =>
                                    setField(
                                        ['video', 'description'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <StringListField
                            id="video-bullets"
                            label="Bullets"
                            value={content.video.bullets}
                            onChange={(value) =>
                                setField(['video', 'bullets'], value)
                            }
                        />
                        <FieldRow>
                            <div className="grid gap-1.5">
                                <Label htmlFor="video-duration-label">
                                    {t('Duration label')}
                                </Label>
                                <Input
                                    id="video-duration-label"
                                    value={content.video.duration_label}
                                    onChange={(event) =>
                                        setField(
                                            ['video', 'duration_label'],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="video-tag-label">
                                    {t('Tag label')}
                                </Label>
                                <Input
                                    id="video-tag-label"
                                    value={content.video.tag_label}
                                    onChange={(event) =>
                                        setField(
                                            ['video', 'tag_label'],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </FieldRow>
                        <FieldRow>
                            <div className="grid gap-1.5">
                                <Label htmlFor="video-scene-label">
                                    {t('Scene label')}
                                </Label>
                                <Input
                                    id="video-scene-label"
                                    value={content.video.scene_label}
                                    onChange={(event) =>
                                        setField(
                                            ['video', 'scene_label'],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="video-timecode">
                                    {t('Timecode')}
                                </Label>
                                <Input
                                    id="video-timecode"
                                    value={content.video.timecode}
                                    onChange={(event) =>
                                        setField(
                                            ['video', 'timecode'],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="video-card-title">
                                {t('Card title')}
                            </Label>
                            <Input
                                id="video-card-title"
                                value={content.video.card_title}
                                onChange={(event) =>
                                    setField(
                                        ['video', 'card_title'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <StringListField
                            id="video-chips"
                            label="Chips"
                            value={content.video.chips}
                            onChange={(value) =>
                                setField(['video', 'chips'], value)
                            }
                        />
                        <div className="grid gap-1.5">
                            <Label htmlFor="video-footer-title">
                                {t('Footer title')}
                            </Label>
                            <Input
                                id="video-footer-title"
                                value={content.video.footer_title}
                                onChange={(event) =>
                                    setField(
                                        ['video', 'footer_title'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="video-footer-description">
                                {t('Footer description')}
                            </Label>
                            <Textarea
                                id="video-footer-description"
                                value={content.video.footer_description}
                                onChange={(event) =>
                                    setField(
                                        ['video', 'footer_description'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="video-button-label">
                                {t('Button label')}
                            </Label>
                            <Input
                                id="video-button-label"
                                value={content.video.button_label}
                                onChange={(event) =>
                                    setField(
                                        ['video', 'button_label'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </EditorSection>
                </>
            )}

            {activeTab === 'features' && (
                <EditorSection
                    title={t('Feature sections')}
                    description={t(
                        'Manage the where-it-fits cards, feature grid, control area, and setup steps.',
                    )}
                >
                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="fits-badge">
                                {t('Where it fits badge')}
                            </Label>
                            <Input
                                id="fits-badge"
                                value={content.where_it_fits.badge}
                                onChange={(event) =>
                                    setField(
                                        ['where_it_fits', 'badge'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="feature-grid-badge">
                                {t('Feature grid badge')}
                            </Label>
                            <Input
                                id="feature-grid-badge"
                                value={content.feature_grid.badge}
                                onChange={(event) =>
                                    setField(
                                        ['feature_grid', 'badge'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>
                    <div className="grid gap-1.5">
                        <Label htmlFor="fits-title">
                            {t('Where it fits title')}
                        </Label>
                        <Textarea
                            id="fits-title"
                            value={content.where_it_fits.title}
                            onChange={(event) =>
                                setField(
                                    ['where_it_fits', 'title'],
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="feature-grid-title">
                            {t('Feature grid title')}
                        </Label>
                        <Textarea
                            id="feature-grid-title"
                            value={content.feature_grid.title}
                            onChange={(event) =>
                                setField(
                                    ['feature_grid', 'title'],
                                    event.target.value,
                                )
                            }
                        />
                    </div>

                    <IconCardListEditor
                        title={t('Where it fits cards')}
                        items={content.where_it_fits.cards}
                        onChange={(items) =>
                            setList(['where_it_fits', 'cards'], items)
                        }
                        addLabel={t('Add fit card')}
                    />

                    <IconCardListEditor
                        title={t('Feature grid cards')}
                        items={content.feature_grid.cards}
                        onChange={(items) =>
                            setList(['feature_grid', 'cards'], items)
                        }
                        addLabel={t('Add feature card')}
                    />

                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="control-badge">
                                {t('Control badge')}
                            </Label>
                            <Input
                                id="control-badge"
                                value={content.control.badge}
                                onChange={(event) =>
                                    setField(
                                        ['control', 'badge'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="steps-badge">
                                {t('Steps badge')}
                            </Label>
                            <Input
                                id="steps-badge"
                                value={content.steps.badge}
                                onChange={(event) =>
                                    setField(
                                        ['steps', 'badge'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>

                    <div className="grid gap-1.5">
                        <Label htmlFor="control-title">
                            {t('Control title')}
                        </Label>
                        <Textarea
                            id="control-title"
                            value={content.control.title}
                            onChange={(event) =>
                                setField(
                                    ['control', 'title'],
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="control-description">
                            {t('Control description')}
                        </Label>
                        <Textarea
                            id="control-description"
                            value={content.control.description}
                            onChange={(event) =>
                                setField(
                                    ['control', 'description'],
                                    event.target.value,
                                )
                            }
                        />
                    </div>

                    <IconCardListEditor
                        title={t('Control callouts')}
                        items={content.control.callouts}
                        onChange={(items) =>
                            setList(['control', 'callouts'], items)
                        }
                        addLabel={t('Add callout')}
                    />

                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="control-settings-card-title">
                                {t('Settings card title')}
                            </Label>
                            <Input
                                id="control-settings-card-title"
                                value={content.control.settings_card_title}
                                onChange={(event) =>
                                    setField(
                                        ['control', 'settings_card_title'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="steps-title">
                                {t('Steps title')}
                            </Label>
                            <Textarea
                                id="steps-title"
                                value={content.steps.title}
                                onChange={(event) =>
                                    setField(
                                        ['steps', 'title'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>

                    <SettingRowsEditor
                        rows={content.control.settings_rows}
                        onChange={(rows) =>
                            setList(['control', 'settings_rows'], rows)
                        }
                    />

                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="control-cancel-label">
                                {t('Cancel label')}
                            </Label>
                            <Input
                                id="control-cancel-label"
                                value={content.control.cancel_label}
                                onChange={(event) =>
                                    setField(
                                        ['control', 'cancel_label'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="control-save-label">
                                {t('Save button label')}
                            </Label>
                            <Input
                                id="control-save-label"
                                value={content.control.save_button_label}
                                onChange={(event) =>
                                    setField(
                                        ['control', 'save_button_label'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>

                    <IconCardListEditor
                        title={t('Setup steps')}
                        items={content.steps.items}
                        onChange={(items) => setList(['steps', 'items'], items)}
                        addLabel={t('Add step')}
                    />
                </EditorSection>
            )}

            {activeTab === 'closing' && (
                <EditorSection
                    title={t('Insights and final CTA')}
                    description={t(
                        'Manage the analytics section and the closing call to action.',
                    )}
                >
                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="insights-badge">
                                {t('Insights badge')}
                            </Label>
                            <Input
                                id="insights-badge"
                                value={content.insights.badge}
                                onChange={(event) =>
                                    setField(
                                        ['insights', 'badge'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="insights-metric-label">
                                {t('Metric label')}
                            </Label>
                            <Input
                                id="insights-metric-label"
                                value={content.insights.metric_label}
                                onChange={(event) =>
                                    setField(
                                        ['insights', 'metric_label'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>
                    <div className="grid gap-1.5">
                        <Label htmlFor="insights-chart-title">
                            {t('Chart title')}
                        </Label>
                        <Textarea
                            id="insights-chart-title"
                            value={content.insights.chart_title}
                            onChange={(event) =>
                                setField(
                                    ['insights', 'chart_title'],
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="insights-chart-description">
                            {t('Chart description')}
                        </Label>
                        <Textarea
                            id="insights-chart-description"
                            value={content.insights.chart_description}
                            onChange={(event) =>
                                setField(
                                    ['insights', 'chart_description'],
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="insights-metric-value">
                                {t('Metric value')}
                            </Label>
                            <Input
                                id="insights-metric-value"
                                value={content.insights.metric_value}
                                onChange={(event) =>
                                    setField(
                                        ['insights', 'metric_value'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="insights-metric-trend">
                                {t('Metric trend')}
                            </Label>
                            <Input
                                id="insights-metric-trend"
                                value={content.insights.metric_trend}
                                onChange={(event) =>
                                    setField(
                                        ['insights', 'metric_trend'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>
                    <FieldRow>
                        <NumberListField
                            id="insights-chart-points"
                            label={t('Chart points')}
                            value={content.insights.chart_points}
                            onChange={(value) =>
                                setField(['insights', 'chart_points'], value)
                            }
                            help={t(
                                'Enter numbers separated by commas or new lines.',
                            )}
                        />
                        <StringListField
                            id="insights-chart-labels"
                            label={t('Chart labels')}
                            value={content.insights.chart_labels}
                            onChange={(value) =>
                                setField(['insights', 'chart_labels'], value)
                            }
                        />
                    </FieldRow>
                    <IconCardListEditor
                        title={t('Insight cards')}
                        items={content.insights.cards}
                        onChange={(items) =>
                            setList(['insights', 'cards'], items)
                        }
                        addLabel={t('Add insight card')}
                    />

                    <div className="grid gap-1.5">
                        <Label htmlFor="final-cta-title">
                            {t('Final CTA title')}
                        </Label>
                        <Textarea
                            id="final-cta-title"
                            value={content.final_cta.title}
                            onChange={(event) =>
                                setField(
                                    ['final_cta', 'title'],
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="final-cta-description">
                            {t('Final CTA description')}
                        </Label>
                        <Textarea
                            id="final-cta-description"
                            value={content.final_cta.description}
                            onChange={(event) =>
                                setField(
                                    ['final_cta', 'description'],
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="final-cta-primary-label">
                                {t('Primary button label')}
                            </Label>
                            <Input
                                id="final-cta-primary-label"
                                value={content.final_cta.primary_button_label}
                                onChange={(event) =>
                                    setField(
                                        ['final_cta', 'primary_button_label'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="final-cta-primary-href">
                                {t('Primary button href')}
                            </Label>
                            <Input
                                id="final-cta-primary-href"
                                value={content.final_cta.primary_button_href}
                                onChange={(event) =>
                                    setField(
                                        ['final_cta', 'primary_button_href'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>
                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="final-cta-secondary-label">
                                {t('Secondary button label')}
                            </Label>
                            <Input
                                id="final-cta-secondary-label"
                                value={content.final_cta.secondary_button_label}
                                onChange={(event) =>
                                    setField(
                                        ['final_cta', 'secondary_button_label'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="final-cta-secondary-href">
                                {t('Secondary button href')}
                            </Label>
                            <Input
                                id="final-cta-secondary-href"
                                value={content.final_cta.secondary_button_href}
                                onChange={(event) =>
                                    setField(
                                        ['final_cta', 'secondary_button_href'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>
                </EditorSection>
            )}

            {activeTab === 'closing' && (
                <EditorSection
                    title={t('Testimonials')}
                    description={t(
                        'Quotes shown in the marketing-site testimonial carousel. Edit, reorder by removing and re-adding, or leave the items blank to hide the section.',
                    )}
                >
                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="testimonials-badge">
                                {t('Badge')}
                            </Label>
                            <Input
                                id="testimonials-badge"
                                value={content.testimonials.badge}
                                onChange={(event) =>
                                    setField(
                                        ['testimonials', 'badge'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="testimonials-kicker">
                                {t('Kicker')}
                            </Label>
                            <Input
                                id="testimonials-kicker"
                                value={content.testimonials.kicker}
                                onChange={(event) =>
                                    setField(
                                        ['testimonials', 'kicker'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>
                    <div className="grid gap-1.5">
                        <Label htmlFor="testimonials-title">{t('Title')}</Label>
                        <Textarea
                            id="testimonials-title"
                            value={content.testimonials.title}
                            onChange={(event) =>
                                setField(
                                    ['testimonials', 'title'],
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    <TestimonialListEditor
                        items={content.testimonials.items}
                        onChange={(items) =>
                            setField(['testimonials', 'items'], items)
                        }
                    />
                </EditorSection>
            )}

            {activeTab === 'closing' && (
                <EditorSection
                    title={t('FAQ')}
                    description={t(
                        'Questions and answers in the marketing-site FAQ accordion. Removing every item hides the section.',
                    )}
                >
                    <FieldRow>
                        <div className="grid gap-1.5">
                            <Label htmlFor="faq-badge">{t('Badge')}</Label>
                            <Input
                                id="faq-badge"
                                value={content.faq.badge}
                                onChange={(event) =>
                                    setField(
                                        ['faq', 'badge'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="faq-kicker">{t('Kicker')}</Label>
                            <Input
                                id="faq-kicker"
                                value={content.faq.kicker}
                                onChange={(event) =>
                                    setField(
                                        ['faq', 'kicker'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>
                    <div className="grid gap-1.5">
                        <Label htmlFor="faq-title">{t('Title')}</Label>
                        <Textarea
                            id="faq-title"
                            value={content.faq.title}
                            onChange={(event) =>
                                setField(['faq', 'title'], event.target.value)
                            }
                        />
                    </div>
                    <FaqListEditor
                        items={content.faq.items}
                        onChange={(items) => setField(['faq', 'items'], items)}
                    />
                </EditorSection>
            )}

            {activeTab === 'footer' && (
                <EditorSection
                    title={t('Footer')}
                    description={t(
                        'Edit the footer description, social links, grouped links, legal links, and copyright line.',
                    )}
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="footer-brand-description">
                            {t('Brand description')}
                        </Label>
                        <Textarea
                            id="footer-brand-description"
                            value={content.footer.brand_description}
                            onChange={(event) =>
                                setField(
                                    ['footer', 'brand_description'],
                                    event.target.value,
                                )
                            }
                        />
                    </div>

                    <LinkListEditor
                        title={t('Social links')}
                        items={content.footer.socials}
                        onChange={(items) =>
                            setList(['footer', 'socials'], items)
                        }
                        addLabel={t('Add social')}
                    />

                    <FooterGroupsEditor
                        groups={content.footer.groups}
                        onChange={(groups) =>
                            setList(['footer', 'groups'], groups)
                        }
                    />

                    <FieldRow>
                        <div className="grid gap-1.5 md:col-span-2">
                            <Label htmlFor="footer-legal-title">
                                {t('Legal title')}
                            </Label>
                            <Input
                                id="footer-legal-title"
                                value={content.footer.legal_title}
                                onChange={(event) =>
                                    setField(
                                        ['footer', 'legal_title'],
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </FieldRow>

                    <LinkListEditor
                        title={t('Legal links')}
                        items={content.footer.legal_links}
                        onChange={(items) =>
                            setList(['footer', 'legal_links'], items)
                        }
                        addLabel={t('Add legal link')}
                    />

                    <div className="grid gap-1.5">
                        <Label htmlFor="footer-copyright">
                            {t('Copyright')}
                        </Label>
                        <Input
                            id="footer-copyright"
                            value={content.footer.copyright}
                            onChange={(event) =>
                                setField(
                                    ['footer', 'copyright'],
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                </EditorSection>
            )}

            {form.errors.marketing_home_content && (
                <p className="text-sm text-destructive">
                    {form.errors.marketing_home_content}
                </p>
            )}

            <div className="flex justify-end">
                <Button type="submit" disabled={form.processing}>
                    {t('Save marketing content')}
                </Button>
            </div>
        </form>
    );
}
