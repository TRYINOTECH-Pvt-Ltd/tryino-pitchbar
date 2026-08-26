import { Sparkles, UserRound } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useT } from '@/lib/i18n';
import { VERTICAL_BY_ID } from '@/lib/verticals';
import type { VerticalId } from '@/lib/verticals';
import { SAMPLE_PROMPTS } from './scenario-presets';
import type { ShopperSimulation } from './scenario-presets';

export type VerticalOption = {
    slug: VerticalId;
    label: string;
    short_description: string;
    starter_prompts: string[];
    capabilities: string[];
};

type Props = {
    /** Effective site type if the override is "use agent's setting". */
    agentSiteType: VerticalId | null;
    siteTypeOverride: VerticalId | null;
    onSiteTypeOverride: (slug: VerticalId | null) => void;
    languageOverride: string | null;
    onLanguageOverride: (lang: string | null) => void;
    verticals: Record<string, VerticalOption>;
    onPickPrompt: (prompt: string) => void;
    shopperSim: ShopperSimulation;
    onShopperSimChange: (next: ShopperSimulation) => void;
};

export function ScenarioPanel({
    agentSiteType,
    siteTypeOverride,
    onSiteTypeOverride,
    languageOverride,
    onLanguageOverride,
    verticals,
    onPickPrompt,
    shopperSim,
    onShopperSimChange,
}: Props) {
    const { t } = useT();
    const SUPPORTED_LANGUAGES: { code: string; label: string }[] = [
        { code: 'en', label: t('English') },
        { code: 'es', label: t('Spanish') },
        { code: 'fr', label: t('French') },
        { code: 'de', label: t('German') },
        { code: 'pt', label: t('Portuguese') },
        { code: 'ja', label: t('Japanese') },
        { code: 'ar', label: t('Arabic') },
        { code: 'zh', label: t('Chinese') },
    ];
    const effectiveVertical: VerticalId =
        siteTypeOverride ?? agentSiteType ?? 'generic';
    const previewPrompts = SAMPLE_PROMPTS[effectiveVertical] ?? [];
    const verticalEntry = verticals[effectiveVertical];

    return (
        <div className="flex flex-col gap-5">
            <section className="flex flex-col gap-2">
                <div className="flex items-baseline justify-between">
                    <Label className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        {t('Site type')}
                    </Label>
                    {siteTypeOverride !== null && (
                        <button
                            type="button"
                            onClick={() => onSiteTypeOverride(null)}
                            className="text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                        >
                            {t('Reset')}
                        </button>
                    )}
                </div>
                <Select
                    value={siteTypeOverride ?? '__agent__'}
                    onValueChange={(v) =>
                        onSiteTypeOverride(
                            v === '__agent__' ? null : (v as VerticalId),
                        )
                    }
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__agent__">
                            {t('Use agent setting')}
                            {agentSiteType
                                ? ` · ${t(VERTICAL_BY_ID[agentSiteType].name)}`
                                : ` · ${t('Not set')}`}
                        </SelectItem>
                        {Object.values(verticals).map((v) => (
                            <SelectItem key={v.slug} value={v.slug}>
                                {t(VERTICAL_BY_ID[v.slug].name)} —{' '}
                                {v.short_description}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {verticalEntry && verticalEntry.capabilities.length > 0 && (
                    <div className="mt-1 flex flex-wrap gap-1">
                        {verticalEntry.capabilities.map((cap) => (
                            <Badge
                                key={cap}
                                variant="secondary"
                                className="font-mono text-[10px]"
                            >
                                {cap}
                            </Badge>
                        ))}
                    </div>
                )}
            </section>

            <section className="flex flex-col gap-2">
                <Label className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {t('Reply language')}
                </Label>
                <Select
                    value={languageOverride ?? '__agent__'}
                    onValueChange={(v) =>
                        onLanguageOverride(v === '__agent__' ? null : v)
                    }
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__agent__">
                            {t('Detect / agent default')}
                        </SelectItem>
                        {SUPPORTED_LANGUAGES.map((l) => (
                            <SelectItem key={l.code} value={l.code}>
                                {l.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </section>

            <section className="flex flex-col gap-2">
                <Label className="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    <Sparkles className="h-3 w-3" />
                    {t('Sample prompts')}
                </Label>
                <div className="flex flex-wrap gap-1.5">
                    {previewPrompts.map((prompt) => (
                        <Button
                            key={prompt}
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-auto py-1.5 text-start text-xs leading-snug whitespace-normal"
                            onClick={() => onPickPrompt(prompt)}
                        >
                            {prompt}
                        </Button>
                    ))}
                </div>
            </section>

            <section className="flex flex-col gap-2 rounded-md border bg-muted/30 p-3">
                <div className="flex items-center justify-between">
                    <Label className="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        <UserRound className="h-3 w-3" />
                        {t('Shopper simulation')}
                    </Label>
                    <Checkbox
                        id="pg-shopper-toggle"
                        checked={shopperSim.enabled}
                        onCheckedChange={(v) =>
                            onShopperSimChange({
                                ...shopperSim,
                                enabled: v === true,
                            })
                        }
                        aria-label={t('Toggle shopper simulation')}
                    />
                </div>
                <p className="text-xs leading-snug text-muted-foreground">
                    {t(
                        'Rehearse a logged-in WooCommerce customer turn so the lookup_order tool fires. Sandbox-only — never used for real visitor auth.',
                    )}
                </p>
                {shopperSim.enabled && (
                    <div className="mt-1 flex flex-col gap-2">
                        <div className="flex flex-col gap-1">
                            <Label
                                htmlFor="pg-shopper-uid"
                                className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {t('Fake wp_user_id')}
                            </Label>
                            <Input
                                id="pg-shopper-uid"
                                type="number"
                                min={1}
                                value={
                                    shopperSim.wp_user_id === null
                                        ? ''
                                        : String(shopperSim.wp_user_id)
                                }
                                placeholder="42"
                                onChange={(e) => {
                                    const raw = e.target.value;
                                    const parsed =
                                        raw === '' ? null : Number(raw);

                                    onShopperSimChange({
                                        ...shopperSim,
                                        wp_user_id:
                                            parsed !== null &&
                                            Number.isFinite(parsed) &&
                                            parsed > 0
                                                ? Math.floor(parsed)
                                                : null,
                                    });
                                }}
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label
                                htmlFor="pg-shopper-email"
                                className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {t('Fake email')}
                            </Label>
                            <Input
                                id="pg-shopper-email"
                                type="email"
                                value={shopperSim.email}
                                placeholder="shopper@example.com"
                                onChange={(e) =>
                                    onShopperSimChange({
                                        ...shopperSim,
                                        email: e.target.value,
                                    })
                                }
                            />
                            <p className="text-[10px] leading-snug text-muted-foreground">
                                {t(
                                    'Email is sha256-hashed server-side, identical to the WP plugin path. Plain text never persists.',
                                )}
                            </p>
                        </div>
                    </div>
                )}
            </section>
        </div>
    );
}
