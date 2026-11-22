import { router, usePage } from '@inertiajs/react';
import { ArrowDown, LoaderCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

const THRESHOLD = 72;

export function PullToRefresh() {
    const { url, component } = usePage();
    const indicator = useRef<HTMLDivElement>(null);
    const busy = useRef(false);
    const [phase, setPhase] = useState<'idle' | 'pull' | 'ready' | 'refresh'>('idle');

    useEffect(() => {
        let start: Touch | undefined;
        let distance = 0;

        const reset = () => {
            document.removeEventListener('touchmove', onMove);
            start = undefined;
            distance = 0;
            indicator.current?.style.removeProperty('--pull-distance');
            if (!busy.current) setPhase('idle');
        };

        const onMove = (event: TouchEvent) => {
            if (!start) return;
            const touch = event.touches[0];
            if (!touch || event.touches.length !== 1 || !event.cancelable) return reset();

            const dy = touch.clientY - start.clientY;
            const dx = Math.abs(touch.clientX - start.clientX);
            if (!distance && Math.max(dx, Math.abs(dy)) < 8) return;
            if (dy <= 0 || (!distance && dx >= dy) || window.scrollY > 1) return reset();

            event.preventDefault();
            distance = Math.min(110, dy * 0.5);
            indicator.current?.style.setProperty('--pull-distance', `${distance}px`);
            setPhase(distance >= THRESHOLD ? 'ready' : 'pull');
        };

        const onStart = (event: TouchEvent) => {
            if (start) reset();
            if (busy.current || window.scrollY > 1 || event.touches.length !== 1) return;
            if (!(event.target instanceof Element) || !event.target.closest('main[data-pull-to-refresh]')) return;
            if (event.target.closest('input, textarea, select, [contenteditable], [role="dialog"], [data-no-pull-refresh]')) return;

            start = event.touches[0];
            document.addEventListener('touchmove', onMove, { passive: false });
        };

        const onEnd = () => {
            const shouldRefresh = distance >= THRESHOLD;
            reset();
            if (!shouldRefresh || busy.current) return;
            if (!navigator.onLine) {
                toast.error('Нет подключения к сети');
                return;
            }

            busy.current = true;
            setPhase('refresh');
            const reportError = () => {
                toast.error('Не удалось обновить страницу');
                return false;
            };
            router.reload({
                ...((component === 'feed' || component === 'channel') ? { reset: ['videos'] } : {}),
                onNetworkError: reportError,
                onHttpException: reportError,
                onError: reportError,
                onFinish: () => {
                    busy.current = false;
                    setPhase('idle');
                },
            });
        };

        document.addEventListener('touchstart', onStart, { passive: true });
        document.addEventListener('touchend', onEnd);
        document.addEventListener('touchcancel', reset);
        return () => {
            reset();
            document.removeEventListener('touchstart', onStart);
            document.removeEventListener('touchend', onEnd);
            document.removeEventListener('touchcancel', reset);
        };
    }, [url, component]);

    return (
        <div
            ref={indicator}
            className={cn(
                'pointer-events-none fixed top-[calc(env(safe-area-inset-top)+3.5rem)] left-1/2 z-30 flex size-10 -translate-x-1/2 items-center justify-center rounded-full border bg-card text-brand shadow-sm md:top-[calc(env(safe-area-inset-top)+4rem)]',
                phase === 'idle' && 'opacity-0',
                phase === 'refresh' ? 'translate-y-3' : 'translate-y-[calc(var(--pull-distance,0px)-48px)]',
            )}
            role="status"
        >
            {phase === 'refresh' ? (
                <>
                    <LoaderCircle className="size-5 motion-safe:animate-spin" aria-hidden />
                    <span className="sr-only">Обновляем страницу</span>
                </>
            ) : (
                <ArrowDown className={cn('size-5 motion-safe:transition-transform', phase === 'ready' && 'rotate-180')} aria-hidden />
            )}
        </div>
    );
}
