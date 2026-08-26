export type Workspace = {
    id: string;
    name: string;
    slug: string;
};

export type WorkspaceMembership = Workspace & {
    pivot: {
        role: 'owner' | 'admin' | 'editor' | 'viewer';
    };
};

export type FlashBag = {
    success?: string | null;
    error?: string | null;
};
