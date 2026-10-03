import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export function EmptyState({ icon: Icon, title, children }: { icon: LucideIcon; title: string; children?: ReactNode }) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-border px-6 py-20 text-center">
            <div className="flex size-14 items-center justify-center rounded-2xl bg-muted">
                <Icon className="size-6 text-muted-foreground" />
            </div>
            <h2 className="font-display text-lg font-semibold">{title}</h2>
            {children && <div className="max-w-sm text-sm text-muted-foreground">{children}</div>}
        </div>
    );
}

export function PageTitle({ children, actions }: { children: ReactNode; actions?: ReactNode }) {
    return (
        <div className="mb-6 flex flex-col gap-4 md:mb-8 md:flex-row md:items-end md:justify-between">
            <h1 className="font-display text-3xl font-bold md:text-4xl">{children}</h1>
            {actions}
        </div>
    );
}
