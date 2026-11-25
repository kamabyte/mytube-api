import { Link, router, usePage } from '@inertiajs/react';
import { ArrowDownToLine } from 'lucide-react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import type { SharedProps } from '@/types';

export function DownloadsLink() {
    const { downloads } = usePage<SharedProps>().props;
    const queued = downloads?.queued ?? 0;
    const busy = queued > 0;
    useEffect(() => {
        if (!busy) return;
        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') router.reload({ only: ['downloads'] });
        }, 20_000);
        return () => window.clearInterval(timer);
    }, [busy]);

    return (
        <Button asChild variant="outline" className="min-h-11 rounded-full">
            <Link href="/downloads">
                <ArrowDownToLine className={downloads?.active ? 'animate-pulse text-brand' : ''} />
                Очередь сервера
                {busy && <span className="rounded-full bg-muted px-1.5 text-xs tabular-nums">{queued > 99 ? '99+' : queued}</span>}
            </Link>
        </Button>
    );
}
