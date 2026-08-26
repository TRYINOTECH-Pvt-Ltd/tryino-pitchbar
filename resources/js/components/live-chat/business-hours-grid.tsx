import { Plus, X } from 'lucide-react';
import { useMemo } from 'react';
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

export type Window = { start: string; end: string };

export type BusinessHoursValue = {
    enabled: boolean;
    timezone: string;
    schedule: Record<string, Window[]>;
} | null;

const DAY_KEYS: string[] = [
    'monday',
    'tuesday',
    'wednesday',
    'thursday',
    'friday',
    'saturday',
    'sunday',
];

// Curated short list of timezones operators actually pick from. The
// "Other…" option falls through to a free-text Input so any IANA
// identifier can be entered.
const COMMON_TIMEZONES = [
    'UTC',
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Los_Angeles',
    'America/Sao_Paulo',
    'Europe/London',
    'Europe/Paris',
    'Europe/Berlin',
    'Europe/Madrid',
    'Asia/Dubai',
    'Asia/Kolkata',
    'Asia/Singapore',
    'Asia/Tokyo',
    'Australia/Sydney',
];

// Non-nullable internal type: the editor always operates on a fully
// populated config. The exposed BusinessHoursValue is `… | null`
// because the server stores null when the buyer hasn't configured
// anything yet.
type FilledValue = NonNullable<BusinessHoursValue>;

const DEFAULT: FilledValue = {
    enabled: false,
    timezone: 'UTC',
    schedule: Object.fromEntries(
        DAY_KEYS.map((key) => [
            key,
            key === 'saturday' || key === 'sunday'
                ? []
                : [{ start: '09:00', end: '17:00' }],
        ]),
    ),
};

/**
 * Visual editor for the workspace's business_hours JSON. Replaces the
 * raw textarea with a 7-row grid: per-day "Closed" toggle + repeatable
 * time-window inputs.
 *
 * State shape on the wire matches the BusinessHours service's
 * expectation exactly — no transformation needed in the parent.
 */
export function BusinessHoursGrid({
    value,
    onChange,
}: {
    value: BusinessHoursValue;
    onChange: (next: BusinessHoursValue) => void;
}) {
    const { t } = useT();
    const DAYS: { key: string; label: string }[] = [
        { key: 'monday', label: t('Mon') },
        { key: 'tuesday', label: t('Tue') },
        { key: 'wednesday', label: t('Wed') },
        { key: 'thursday', label: t('Thu') },
        { key: 'friday', label: t('Fri') },
        { key: 'saturday', label: t('Sat') },
        { key: 'sunday', label: t('Sun') },
    ];
    const v: FilledValue = value ?? DEFAULT;
    const isCustomTz = useMemo(
        () => !COMMON_TIMEZONES.includes(v.timezone),
        [v.timezone],
    );

    const setEnabled = (enabled: boolean) => {
        onChange({ ...v, enabled });
    };
    const setTimezone = (timezone: string) => {
        onChange({ ...v, timezone });
    };
    const setDayWindows = (day: string, windows: Window[]) => {
        onChange({
            ...v,
            schedule: { ...v.schedule, [day]: windows },
        });
    };

    return (
        <div className="grid gap-4">
            <div className="flex items-center justify-between gap-3 rounded-md border bg-muted/30 p-3">
                <div className="flex items-center gap-2">
                    <Checkbox
                        id="bh_enabled"
                        checked={v.enabled}
                        onCheckedChange={(c) => setEnabled(c === true)}
                    />
                    <Label htmlFor="bh_enabled" className="text-sm font-medium">
                        {t('Enforce business hours')}
                    </Label>
                </div>
                <span className="text-xs text-muted-foreground">
                    {v.enabled
                        ? t(
                              'Outside the schedule, visitors get the "we\'re closed" treatment.',
                          )
                        : t(
                              'Off → always-on (visitors can request a human anytime).',
                          )}
                </span>
            </div>

            <div className="grid gap-1.5">
                <Label>{t('Timezone')}</Label>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_auto]">
                    <Select
                        value={isCustomTz ? '__custom__' : v.timezone}
                        onValueChange={(tz) => {
                            if (tz === '__custom__') {
                                setTimezone('');
                            } else {
                                setTimezone(tz);
                            }
                        }}
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {COMMON_TIMEZONES.map((tz) => (
                                <SelectItem key={tz} value={tz}>
                                    {tz}
                                </SelectItem>
                            ))}
                            <SelectItem value="__custom__">
                                {t('Other (any IANA tz)…')}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    {isCustomTz && (
                        <Input
                            type="text"
                            value={v.timezone}
                            onChange={(e) => setTimezone(e.target.value)}
                            placeholder={t('e.g. Pacific/Auckland')}
                        />
                    )}
                </div>
            </div>

            <div className="overflow-hidden rounded-md border">
                {DAYS.map((day, i) => {
                    const windows = v.schedule[day.key] ?? [];
                    const closed = windows.length === 0;

                    return (
                        <div
                            key={day.key}
                            className={`flex flex-wrap items-start gap-3 px-3 py-2.5 ${
                                i < DAYS.length - 1 ? 'border-b' : ''
                            } ${closed ? 'bg-muted/30' : ''}`}
                        >
                            <div className="flex w-16 shrink-0 items-center gap-2 pt-1.5">
                                <span className="text-xs font-semibold tracking-wider uppercase">
                                    {day.label}
                                </span>
                            </div>
                            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                                {closed ? (
                                    <p className="pt-1.5 text-xs text-muted-foreground">
                                        {t('Closed all day')}
                                    </p>
                                ) : (
                                    windows.map((w, wi) => (
                                        <div
                                            key={wi}
                                            className="flex items-center gap-2"
                                        >
                                            <Input
                                                type="time"
                                                value={w.start}
                                                onChange={(e) => {
                                                    const next = [...windows];
                                                    next[wi] = {
                                                        ...next[wi],
                                                        start: e.target.value,
                                                    };
                                                    setDayWindows(
                                                        day.key,
                                                        next,
                                                    );
                                                }}
                                                className="h-8 w-28 text-sm"
                                            />
                                            <span className="text-muted-foreground">
                                                –
                                            </span>
                                            <Input
                                                type="time"
                                                value={w.end}
                                                onChange={(e) => {
                                                    const next = [...windows];
                                                    next[wi] = {
                                                        ...next[wi],
                                                        end: e.target.value,
                                                    };
                                                    setDayWindows(
                                                        day.key,
                                                        next,
                                                    );
                                                }}
                                                className="h-8 w-28 text-sm"
                                            />
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setDayWindows(
                                                        day.key,
                                                        windows.filter(
                                                            (_, j) => j !== wi,
                                                        ),
                                                    )
                                                }
                                                className="h-7 w-7 p-0 text-muted-foreground hover:text-red-600"
                                            >
                                                <X className="size-3" />
                                            </Button>
                                        </div>
                                    ))
                                )}
                            </div>
                            <div className="flex shrink-0 items-center gap-2 pt-1">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setDayWindows(day.key, [
                                            ...windows,
                                            { start: '09:00', end: '17:00' },
                                        ])
                                    }
                                    className="h-7 text-xs"
                                >
                                    <Plus className="me-1 size-3" />
                                    {t('Window')}
                                </Button>
                                <div className="flex items-center gap-1.5">
                                    <Checkbox
                                        id={`closed_${day.key}`}
                                        checked={closed}
                                        onCheckedChange={(c) =>
                                            setDayWindows(
                                                day.key,
                                                c === true
                                                    ? []
                                                    : [
                                                          {
                                                              start: '09:00',
                                                              end: '17:00',
                                                          },
                                                      ],
                                            )
                                        }
                                    />
                                    <Label
                                        htmlFor={`closed_${day.key}`}
                                        className="text-xs text-muted-foreground"
                                    >
                                        {t('Closed')}
                                    </Label>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
