import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { type ReactNode, useEffect, useRef, useState } from 'react';
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
    const [edges, setEdges] = useState({ start: true, end: children.length <= 1 });

    useEffect(() => {
        const el = scroller.current;
        if (!el) return;
        const first = el.firstElementChild;
        const last = el.lastElementChild;

        if (!first || !last) {
            setEdges({ start: true, end: true });
            return;
        }

        // Only edge visibility affects the arrows. Keep React out of the
        // scrolling path instead of measuring layout and rendering every frame.
        const observer = new IntersectionObserver((entries) => {
            setEdges((previous) => {
                let { start, end } = previous;

                for (const entry of entries) {
                    const visible = entry.isIntersecting && entry.intersectionRatio >= 0.99;
                    if (entry.target === first) start = visible;
                    if (entry.target === last) end = visible;
                }

                return start === previous.start && end === previous.end ? previous : { start, end };
            });
        }, { root: el, threshold: 0.99 });

        observer.observe(first);
        observer.observe(last);
        return () => observer.disconnect();
    }, [children.length]);

    const scrollBy = (direction: 1 | -1) => {
        const el = scroller.current;
        if (!el) return;
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        el.scrollBy({ left: direction * el.clientWidth * 0.85, behavior: reducedMotion ? 'instant' : 'smooth' });
    };

    return (
        <section
            className="group/shelf -mx-4 md:-mx-6 lg:-mx-8"
            // Long home pages have hundreds of cards. Keep offscreen rows out
            // of layout/paint, retaining their measured height and scroll state.
            style={{ contain: 'layout style', contentVisibility: 'auto', containIntrinsicBlockSize: 'auto 18rem' }}
        >
            <div className="mb-3 flex items-center gap-3 px-4 md:px-6 lg:px-8">
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
                className="scrollbar-none flex snap-x snap-proximity scroll-px-4 gap-4 overflow-x-auto overscroll-x-contain px-4 pb-2 md:scroll-px-6 md:px-6 lg:scroll-px-8 lg:px-8"
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
