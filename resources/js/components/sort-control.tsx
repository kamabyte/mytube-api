import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { VideoSort } from '@/types';

const LABELS: Record<VideoSort, string> = {
    new: 'Новые',
    added: 'Недавно скачанные',
    popular: 'Популярные',
    old: 'Старые',
};

/** Сегментированный переключатель сортировки в стиле iOS. */
export function SortControl({ value, options = ['new', 'added', 'popular', 'old'] }: { value: VideoSort; options?: VideoSort[] }) {
    const select = (sort: VideoSort) => {
        if (sort === value) return;
        router.get(window.location.pathname, sort === 'new' ? {} : { sort }, { preserveScroll: true, preserveState: false, replace: true });
    };

    return (
        <div role="tablist" aria-label="Сортировка" className="scrollbar-none -mx-1 flex max-w-full gap-1 overflow-x-auto px-1">
            {options.map((sort) => (
                <button
                    key={sort}
                    role="tab"
                    aria-selected={sort === value}
                    onClick={() => select(sort)}
                    className={cn(
                        'h-8 shrink-0 rounded-full px-3.5 text-[13px] font-medium whitespace-nowrap transition-colors',
                        sort === value
                            ? 'bg-foreground text-background'
                            : 'bg-black/[0.05] text-foreground/80 hover:bg-black/[0.09] dark:bg-white/[0.07] dark:hover:bg-white/[0.12]',
                    )}
                >
                    {LABELS[sort]}
                </button>
            ))}
        </div>
    );
}
