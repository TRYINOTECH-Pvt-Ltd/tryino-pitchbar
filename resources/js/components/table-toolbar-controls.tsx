import { ChevronDown, Columns3, Filter, ListFilter } from 'lucide-react';
import { Fragment } from 'react';
import type { ComponentProps } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { TableColumnOption } from '@/hooks/use-persistent-table-columns';
import { useT } from '@/lib/i18n';
import { replaceTableQuery } from '@/lib/table-query';
import { cn } from '@/lib/utils';

export type TableMenuOption<Value extends string = string> = {
    value: Value;
    label: string;
};

type TableFilterGroup<Value extends string = string> = {
    label: string;
    paramName: string;
    value: Value;
    defaultValue: Value;
    options: readonly TableMenuOption<Value>[];
};

type SharedMenuProps = {
    only?: string[];
};

function ToolbarMenuButton({
    children,
    className,
    ...props
}: Omit<ComponentProps<typeof Button>, 'size' | 'variant'>) {
    return (
        <Button
            variant="outline"
            size="sm"
            className={cn(
                'h-7 gap-1.5 rounded-md bg-card px-2.5 text-xs font-normal text-muted-foreground shadow-xs',
                className,
            )}
            {...props}
        >
            {children}
        </Button>
    );
}

function CounterBadge({ count }: { count: number }) {
    return (
        <span className="inline-flex min-w-4 items-center justify-center rounded bg-foreground px-1 text-[10px] font-medium text-background">
            {count}
        </span>
    );
}

export function TableScopeMenu<Value extends string>({
    options,
    value,
    paramName,
    only,
}: SharedMenuProps & {
    options: readonly TableMenuOption<Value>[];
    value: Value;
    paramName: string;
}) {
    const { t } = useT();
    const defaultValue = options[0]?.value;
    const currentOption =
        options.find((option) => option.value === value) ?? options[0];

    if (!currentOption || !defaultValue) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <ToolbarMenuButton>
                    {currentOption.label}
                    <ChevronDown className="size-3.5" />
                </ToolbarMenuButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-48">
                <DropdownMenuLabel>{t('View')}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuRadioGroup
                    value={value}
                    onValueChange={(nextValue) => {
                        replaceTableQuery(
                            {
                                [paramName]:
                                    nextValue === defaultValue
                                        ? null
                                        : nextValue,
                            },
                            { only },
                        );
                    }}
                >
                    {options.map((option) => (
                        <DropdownMenuRadioItem
                            key={option.value}
                            value={option.value}
                        >
                            {option.label}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function TableSortMenu<Value extends string>({
    options,
    value,
    paramName,
    only,
}: SharedMenuProps & {
    options: readonly TableMenuOption<Value>[];
    value: Value;
    paramName: string;
}) {
    const { t } = useT();
    const defaultValue = options[0]?.value;

    if (!defaultValue) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <ToolbarMenuButton>
                    <ListFilter className="size-3.5" />
                    {t('Sort')}
                </ToolbarMenuButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-52">
                <DropdownMenuLabel>{t('Sort by')}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuRadioGroup
                    value={value}
                    onValueChange={(nextValue) => {
                        replaceTableQuery(
                            {
                                [paramName]:
                                    nextValue === defaultValue
                                        ? null
                                        : nextValue,
                            },
                            { only },
                        );
                    }}
                >
                    {options.map((option) => (
                        <DropdownMenuRadioItem
                            key={option.value}
                            value={option.value}
                        >
                            {option.label}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function TableFiltersMenu({
    groups,
    only,
}: SharedMenuProps & {
    groups: readonly TableFilterGroup[];
}) {
    const { t } = useT();
    const activeFilterCount = groups.filter(
        (group) => group.value !== group.defaultValue,
    ).length;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <ToolbarMenuButton>
                    <Filter className="size-3.5" />
                    {t('Filters')}
                    {activeFilterCount > 0 && (
                        <CounterBadge count={activeFilterCount} />
                    )}
                </ToolbarMenuButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
                <DropdownMenuLabel>{t('Filters')}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {groups.map((group, index) => (
                    <Fragment key={group.paramName}>
                        {index > 0 && <DropdownMenuSeparator />}
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            {group.label}
                        </DropdownMenuLabel>
                        <DropdownMenuRadioGroup
                            value={group.value}
                            onValueChange={(nextValue) => {
                                replaceTableQuery(
                                    {
                                        [group.paramName]:
                                            nextValue === group.defaultValue
                                                ? null
                                                : nextValue,
                                    },
                                    { only },
                                );
                            }}
                        >
                            {group.options.map((option) => (
                                <DropdownMenuRadioItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </DropdownMenuRadioItem>
                            ))}
                        </DropdownMenuRadioGroup>
                    </Fragment>
                ))}
                {activeFilterCount > 0 && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() => {
                                replaceTableQuery(
                                    Object.fromEntries(
                                        groups.map((group) => [
                                            group.paramName,
                                            null,
                                        ]),
                                    ),
                                    { only },
                                );
                            }}
                        >
                            {t('Clear filters')}
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function TableColumnMenu<Id extends string>({
    columns,
    visibleColumns,
    hiddenColumnCount,
    onResetColumns,
    onSetColumnVisibility,
}: {
    columns: readonly TableColumnOption<Id>[];
    visibleColumns: readonly Id[];
    hiddenColumnCount: number;
    onResetColumns: () => void;
    onSetColumnVisibility: (id: Id, visible: boolean) => void;
}) {
    const { t } = useT();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <ToolbarMenuButton>
                    <Columns3 className="size-3.5" />
                    {t('Column settings')}
                    {hiddenColumnCount > 0 && (
                        <CounterBadge count={hiddenColumnCount} />
                    )}
                </ToolbarMenuButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel>{t('Visible columns')}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {columns.map((column) => (
                    <DropdownMenuCheckboxItem
                        key={column.id}
                        checked={visibleColumns.includes(column.id)}
                        disabled={column.canHide === false}
                        onCheckedChange={(checked) => {
                            onSetColumnVisibility(column.id, checked === true);
                        }}
                    >
                        {column.label}
                    </DropdownMenuCheckboxItem>
                ))}
                {hiddenColumnCount > 0 && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onSelect={() => onResetColumns()}>
                            {t('Reset columns')}
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
