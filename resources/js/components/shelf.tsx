import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { type ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * Горизонтальная полка, как ряды в Apple TV: прокрутка свайпом или стрелками,
 * карточки прилипают к краю.
 */
export function Shelf({
    title,
    href,
    icon,
    children,
    itemClassName = 'w-[72vw] sm:w-[300px] lg:w-[320px]',
}: {
    title: ReactNode;
    href?: string;
    icon?: ReactNode;
    children: ReactNode[];
    itemClassName?: string;
}) {
    const scroller = useRef<HTMLDivElement>(null);
    const [edges, setEdges] = useState({ start: true, end: false });

    const update = useCallback(() => {
        const el = scroller.current;
        if (!el) return;
        setEdges({ start: el.scrollLeft < 8, end: el.scrollLeft + el.clientWidth >= el.scrollWidth - 8 });
    }, []);

    useEffect(() => {
        update();
        const el = scroller.current;
        if (!el) return;
        const observer = new ResizeObserver(update);
        observer.observe(el);
        return () => observer.disconnect();
    }, [update]);

    const scrollBy = (direction: 1 | -1) => {
        const el = scroller.current;
        if (!el) return;
        el.scrollBy({ left: direction * el.clientWidth * 0.85, behavior: 'smooth' });
    };

    return (
        <section className="group/shelf">
            <div className="mb-3 flex items-center gap-3">
                {icon}
                <h2 className="font-display min-w-0 truncate text-xl font-semibold md:text-[22px]">
                    {href ? (
                        <Link href={href} className="inline-flex items-center gap-1 hover:opacity-80">
                            {title}
                            <ChevronRight className="size-5 text-muted-foreground" />
                        </Link>
                    ) : (
                        title
                    )}
                </h2>
                <div className="ml-auto hidden gap-1.5 md:flex">
                    <Button variant="secondary" size="icon" className="size-8 rounded-full" disabled={edges.start} onClick={() => scrollBy(-1)} aria-label="Назад">
                        <ChevronLeft className="size-4" />
                    </Button>
                    <Button variant="secondary" size="icon" className="size-8 rounded-full" disabled={edges.end} onClick={() => scrollBy(1)} aria-label="Вперёд">
                        <ChevronRight className="size-4" />
                    </Button>
                </div>
            </div>
            <div
                ref={scroller}
                onScroll={update}
                className="scrollbar-none -mx-4 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto px-4 pb-2 md:-mx-6 md:scroll-px-6 md:px-6 lg:-mx-8 lg:scroll-px-8 lg:px-8"
            >
                {children.map((child, index) => (
                    <div key={index} className={cn('shrink-0 snap-start', itemClassName)}>
                        {child}
                    </div>
                ))}
            </div>
        </section>
    );
}
