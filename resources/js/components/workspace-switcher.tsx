import { router, useForm, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronDown, Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SidebarMenuButton, useSidebar } from '@/components/ui/sidebar';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    select as selectWorkspace,
    store as createWorkspace,
} from '@/routes/workspaces';
import type { Workspace, WorkspaceMembership } from '@/types';

type SharedProps = {
    currentWorkspace: Workspace | null;
    workspaces: WorkspaceMembership[];
};

function CreateWorkspaceDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useT();
    const form = useForm<{ name: string }>({ name: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(createWorkspace.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>{t('Create new workspace')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'A workspace keeps agents, conversations, and members separate. You can switch between workspaces from this menu.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2 py-4">
                        <Label htmlFor="new-workspace-name">{t('Name')}</Label>
                        <Input
                            id="new-workspace-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder={t('Acme HQ')}
                            autoFocus
                        />
                        {form.errors.name && (
                            <p className="text-xs text-destructive">
                                {form.errors.name}
                            </p>
                        )}
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={form.processing}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {t('Create workspace')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Multi-workspace switcher. Shows the current workspace name in the
 * sidebar; clicking opens a dropdown of every workspace the visitor
 * is a member of so they can flip between them without re-logging.
 *
 * When the visitor is a member of only one workspace the dropdown is
 * pointless clutter — a list of one with a checkmark next to itself.
 * In that case we render the workspace name as a plain non-interactive
 * label instead.
 */
export function WorkspaceSwitcher() {
    const { t } = useT();
    const page = usePage<SharedProps>();
    const { state } = useSidebar();
    const current = page.props.currentWorkspace;
    const workspaces = page.props.workspaces ?? [];
    const [createOpen, setCreateOpen] = useState(false);

    if (!current) {
        return null;
    }

    const isCollapsed = state === 'collapsed';
    const triggerSide = isCollapsed ? 'right' : 'bottom';
    const triggerAlign = isCollapsed ? 'center' : 'start';
    const triggerSideOffset = isCollapsed ? 8 : 6;

    // Light mode: solid sidebar-accent surface with a subtle border so
    // the label reads as a quiet eyebrow on a cream sidebar.
    // Dark mode: rich gradient + glassy highlight, matching the
    // Getting Started / Upgrade cards below.
    const expandedCardClassName =
        'flex h-[34px] w-full items-center gap-2 rounded-lg border px-3 text-start transition-all duration-200 ' +
        'border-sidebar-border/70 bg-sidebar-accent/40 text-sidebar-foreground hover:bg-sidebar-accent/60 ' +
        'dark:border-white/10 dark:bg-[radial-gradient(circle_at_top_left,rgba(255,255,255,0.075),transparent_44%),linear-gradient(180deg,rgba(20,22,25,0.96),rgba(13,14,17,0.98))] dark:shadow-[0_18px_44px_-36px_rgba(0,0,0,0.95),inset_0_1px_0_rgba(255,255,255,0.045)] dark:hover:border-white/15 dark:hover:bg-[radial-gradient(circle_at_top_left,rgba(255,255,255,0.105),transparent_48%),linear-gradient(180deg,rgba(24,26,30,0.98),rgba(14,15,19,1))]';

    const expandedCardContent = (
        <>
            <span className="flex size-5 shrink-0 items-center justify-center text-sidebar-foreground/70">
                <Building2 className="size-4 stroke-[1.65]" />
            </span>
            <span className="flex min-w-0 flex-1 items-center gap-3">
                <span className="truncate text-[13px] leading-none font-semibold text-sidebar-foreground">
                    {current.name}
                </span>
            </span>
        </>
    );

    const dropdownContent = (
        <DropdownMenuContent
            align={triggerAlign}
            side={triggerSide}
            sideOffset={triggerSideOffset}
            className="w-56"
        >
            <DropdownMenuLabel>{t('Switch workspace')}</DropdownMenuLabel>
            <DropdownMenuSeparator />
            {workspaces.map((ws) => (
                <DropdownMenuItem
                    key={ws.id}
                    onSelect={() => {
                        if (ws.id === current.id) {
                            return;
                        }

                        router.post(
                            selectWorkspace.url(ws.id),
                            {},
                            { preserveScroll: true },
                        );
                    }}
                >
                    <span className="flex-1 truncate">{ws.name}</span>
                    {ws.id === current.id && <Check className="ms-2 size-4" />}
                </DropdownMenuItem>
            ))}
            <DropdownMenuSeparator />
            <DropdownMenuItem
                onSelect={(e) => {
                    e.preventDefault();
                    setCreateOpen(true);
                }}
            >
                <Plus className="me-2 size-4" />
                <span>{t('Create new workspace')}</span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    );

    const createDialog = (
        <CreateWorkspaceDialog open={createOpen} onOpenChange={setCreateOpen} />
    );

    if (isCollapsed) {
        return (
            <>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="sm"
                            tooltip={{ children: current.name }}
                            className="h-9 rounded-xl border border-sidebar-border/60 bg-sidebar-accent/35"
                        >
                            <Building2 className="size-4" />
                            <span>{current.name}</span>
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    {dropdownContent}
                </DropdownMenu>
                {createDialog}
            </>
        );
    }

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        className={cn(
                            expandedCardClassName,
                            'focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none',
                        )}
                        aria-label={t('Workspace: :name', {
                            name: current.name,
                        })}
                    >
                        {expandedCardContent}
                        <ChevronDown className="ms-auto size-4 shrink-0 text-sidebar-foreground/70" />
                    </button>
                </DropdownMenuTrigger>
                {dropdownContent}
            </DropdownMenu>
            {createDialog}
        </>
    );
}
