import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ImageOff, Info, Pause, Play } from 'lucide-react';
import { type ReactNode, useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { ChannelAvatar } from '@/components/channel-avatar';
import { formatDuration, formatRelative } from '@/lib/format';
import { cn } from '@/lib/utils';
import { progressRatio, useProgressStore } from '@/lib/watch-progress';
import type { Video } from '@/types';

const INTERVAL = 8000;

/** Витрина: нативный свайп двигает весь слайд вместе с картинкой и текстом. */
export function Hero({ videos }: { videos: Video[] }) {
    const track = useRef<HTMLDivElement>(null);
    const container = useRef<HTMLElement>(null);
    const active = useRef(0);
    const [index, setIndex] = useState(0);
    const [autoPlay, setAutoPlay] = useState(true);
    const [hovered, setHovered] = useState(false);
    const [focusPaused, setFocusPaused] = useState(false);
    const [visible, setVisible] = useState(false);
    const [pageVisible, setPageVisible] = useState(true);
    const progress = useProgressStore();
    const count = videos.length;
    const loop = count > 1;

    const moveTo = useCallback((position: number) => {
        const el = track.current;
        if (!el) return;
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        el.scrollTo({ left: position * el.clientWidth, behavior: reduced ? 'instant' : 'smooth' });
    }, []);

    const advance = useCallback((direction: 1 | -1) => {
        const el = track.current;
        if (!el || count < 2) return;
        let position = Math.round(el.scrollLeft / el.clientWidth);

        // Крайние копии обеспечивают переход через границу за один слайд.
        if (position === 0 || position === count + 1) {
            position = position === 0 ? count : 1;
            el.scrollTo({ left: position * el.clientWidth, behavior: 'instant' });
        }

        moveTo(position + direction);
    }, [count, moveTo]);

    useLayoutEffect(() => {
        const el = track.current;
        if (!el || count === 0) return;
        let width = el.clientWidth;
        let touching = false;
        let timer: number | undefined;
        active.current = Math.min(active.current, count - 1);
        setIndex(active.current);
        el.scrollTo({ left: (active.current + (loop ? 1 : 0)) * width, behavior: 'instant' });

        const select = (position: number) => {
            const next = loop ? (position - 1 + count) % count : 0;
            active.current = next;
            setIndex((previous) => previous === next ? previous : next);
        };
        const settle = () => {
            if (touching || !width) return;
            const position = Math.round(el.scrollLeft / width);
            if (Math.abs(el.scrollLeft - position * width) > 1) return;
            select(position);

            if (loop && (position === 0 || position === count + 1)) {
                el.scrollTo({ left: (position === 0 ? count : 1) * width, behavior: 'instant' });
            }
        };
        const onScroll = () => {
            window.clearTimeout(timer);
            // scrollend пока есть не во всех версиях Safari.
            timer = window.setTimeout(settle, 160);
        };
        const onTouchStart = () => {
            touching = true;
            window.clearTimeout(timer);
            setAutoPlay(false);
        };
        const onTouchEnd = () => {
            touching = false;
            onScroll();
        };
        const slides = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting && entry.intersectionRatio >= 0.6) {
                    select(Number((entry.target as HTMLElement).dataset.position));
                }
            }
        }, { root: el, threshold: 0.6 });
        for (const slide of el.children) slides.observe(slide);

        const resize = new ResizeObserver(() => {
            if (el.clientWidth === width) return;
            width = el.clientWidth;
            el.scrollTo({ left: (active.current + (loop ? 1 : 0)) * width, behavior: 'instant' });
        });
        resize.observe(el);
        el.addEventListener('scroll', onScroll, { passive: true });
        el.addEventListener('scrollend', settle);
        el.addEventListener('touchstart', onTouchStart, { passive: true });
        el.addEventListener('touchend', onTouchEnd, { passive: true });
        el.addEventListener('touchcancel', onTouchEnd, { passive: true });

        return () => {
            window.clearTimeout(timer);
            slides.disconnect();
            resize.disconnect();
            el.removeEventListener('scroll', onScroll);
            el.removeEventListener('scrollend', settle);
            el.removeEventListener('touchstart', onTouchStart);
            el.removeEventListener('touchend', onTouchEnd);
            el.removeEventListener('touchcancel', onTouchEnd);
        };
    }, [count, loop]);

    useEffect(() => {
        const el = container.current;
        if (!el) return;
        const observer = new IntersectionObserver(([entry]) => {
            setVisible(entry.isIntersecting && entry.intersectionRatio >= 0.25);
        }, { threshold: 0.25 });
        observer.observe(el);
        const updateVisibility = () => setPageVisible(!document.hidden);
        updateVisibility();
        document.addEventListener('visibilitychange', updateVisibility);
        const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const updateMotion = () => {
            if (motion.matches) setAutoPlay(false);
        };
        updateMotion();
        motion.addEventListener('change', updateMotion);
        return () => {
            observer.disconnect();
            document.removeEventListener('visibilitychange', updateVisibility);
            motion.removeEventListener('change', updateMotion);
        };
    }, [count]);

    useEffect(() => {
        if (!autoPlay || hovered || focusPaused || !visible || !pageVisible || !loop) return;
        const timer = window.setTimeout(() => advance(1), INTERVAL);
        return () => window.clearTimeout(timer);
    }, [index, autoPlay, hovered, focusPaused, visible, pageVisible, loop, advance]);

    if (count === 0) return null;

    const navigate = (direction: 1 | -1) => {
        setAutoPlay(false);
        advance(direction);
    };
    const slides = loop ? [videos[count - 1], ...videos, videos[0]] : videos;

    return (
        <section
            ref={container}
            role="region"
            aria-label="Последние видео"
            aria-roledescription="карусель"
            className="relative isolate overflow-hidden rounded-2xl border bg-card text-card-foreground"
            onPointerEnter={(event) => event.pointerType === 'mouse' && setHovered(true)}
            onPointerLeave={(event) => event.pointerType === 'mouse' && setHovered(false)}
            onFocusCapture={() => setFocusPaused(true)}
            onBlurCapture={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget)) setFocusPaused(false);
            }}
            onKeyDown={(event) => {
                if (!loop || (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight')) return;
                event.preventDefault();
                navigate(event.key === 'ArrowLeft' ? -1 : 1);
            }}
        >
            <div
                ref={track}
                className="scrollbar-none flex snap-x snap-mandatory overflow-x-auto overscroll-x-contain"
                onPointerDown={() => setAutoPlay(false)}
            >
                {slides.map((video, position) => {
                    const realIndex = loop ? position - 1 : position;
                    const selected = realIndex === index;
                    return (
                        <HeroSlide
                            key={`${position}-${video.id}`}
                            video={video}
                            position={position}
                            label={`${(position - (loop ? 1 : 0) + count) % count + 1} из ${count}`}
                            selected={selected}
                            first={realIndex === 0}
                            ratio={progressRatio(progress[video.id])}
                        />
                    );
                })}
            </div>

            {loop && (
                <div className="flex min-h-12 items-center justify-center gap-1 border-t px-3 sm:justify-start sm:px-5 lg:px-7">
                    <SlideButton label="Предыдущий слайд" className="hidden sm:inline-flex" onClick={() => navigate(-1)}>
                        <ChevronLeft className="size-5" />
                    </SlideButton>
                    <div className="flex items-center" role="group" aria-label="Выбор слайда">
                        {videos.map((video, i) => (
                            <button
                                key={video.id}
                                type="button"
                                aria-current={i === index ? 'true' : undefined}
                                aria-label={`Слайд ${i + 1}: ${video.name}`}
                                onClick={() => {
                                    setAutoPlay(false);
                                    moveTo(i + 1);
                                }}
                                className="group/dot flex h-11 min-w-7 items-center justify-center rounded-full px-1.5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <span className={cn(
                                    'block h-1.5 rounded-full transition-[width,background-color] duration-200 motion-reduce:transition-none',
                                    i === index ? 'w-5 bg-brand' : 'w-1.5 bg-muted-foreground/50 group-hover/dot:bg-foreground',
                                )} />
                            </button>
                        ))}
                    </div>
                    <SlideButton label="Следующий слайд" className="hidden sm:inline-flex" onClick={() => navigate(1)}>
                        <ChevronRight className="size-5" />
                    </SlideButton>
                    <SlideButton
                        label={autoPlay ? 'Приостановить автопереключение' : 'Включить автопереключение'}
                        onClick={() => {
                            setAutoPlay((playing) => !playing);
                            setFocusPaused(false);
                        }}
                    >
                        {autoPlay ? <Pause className="size-4" /> : <Play className="size-4" />}
                    </SlideButton>
                </div>
            )}
            <p className="sr-only" aria-live={autoPlay ? 'off' : 'polite'} aria-atomic="true">
                Слайд {index + 1} из {count}: {videos[index]?.name}
            </p>
        </section>
    );
}

function HeroSlide({ video, position, label, selected, first, ratio }: {
    video: Video;
    position: number;
    label: string;
    selected: boolean;
    first: boolean;
    ratio: number;
}) {
    const [imageFailed, setImageFailed] = useState(false);
    const playable = video.download_state === 'downloaded';
    const resumable = playable && ratio > 0.02 && ratio < 1;
    const fresh = video.published_at && Date.now() - new Date(video.published_at).getTime() < 3 * 86400_000;

    return (
        <div
            data-position={position}
            role="group"
            aria-roledescription="слайд"
            aria-label={label}
            aria-hidden={!selected}
            inert={!selected}
            className="relative isolate w-full min-w-0 shrink-0 snap-start snap-always overflow-hidden"
            style={{ contain: 'layout paint' }}
        >
            <div className="grid h-full items-center gap-3 p-3 sm:gap-5 sm:p-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)] lg:gap-8 lg:p-7">
                <Link
                    href={`/watch/${video.id}`}
                    className="relative block aspect-video overflow-hidden rounded-xl bg-muted lg:order-2 lg:max-h-72"
                    tabIndex={-1}
                    aria-hidden
                    draggable={false}
                >
                    {video.thumbnail && !imageFailed ? (
                        <img
                            src={video.thumbnail}
                            alt=""
                            draggable={false}
                            decoding="async"
                            fetchPriority={first ? 'high' : 'low'}
                            className="absolute inset-0 size-full object-cover"
                            onError={() => setImageFailed(true)}
                        />
                    ) : (
                        <ImageOff className="absolute top-1/2 left-1/2 size-6 -translate-x-1/2 -translate-y-1/2 text-muted-foreground/60" aria-hidden />
                    )}
                    {video.duration_seconds > 0 && (
                        <span className="absolute right-2 bottom-2 rounded-md bg-black/75 px-1.5 py-0.5 text-xs font-medium text-white tabular-nums">
                            {formatDuration(video.duration_seconds)}
                        </span>
                    )}
                    {resumable && (
                        <div className="absolute inset-x-0 bottom-0 h-1 bg-white/30">
                            <div className="h-full bg-brand" style={{ width: `${ratio * 100}%` }} />
                        </div>
                    )}
                </Link>
                <div className="flex min-w-0 flex-col items-start px-1 pb-1 lg:order-1 lg:px-0">
                    <div className="flex min-h-7 w-full flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground sm:text-sm">
                        {video.channel && (
                            <Link href={`/channels/${video.channel.id}`} className="flex min-w-0 items-center gap-2 font-medium text-foreground hover:underline" draggable={false}>
                                <ChannelAvatar channel={video.channel} className="size-6 shrink-0" />
                                <span className="truncate">{video.channel.name}</span>
                            </Link>
                        )}
                        {fresh && <span className="font-semibold text-brand">Новинка</span>}
                        {video.published_at && <span>{formatRelative(video.published_at)}</span>}
                    </div>
                    <div className="font-display mt-2 min-h-[2lh] text-xl leading-tight font-bold sm:text-2xl lg:mt-3 lg:text-3xl">
                        <h2 className="line-clamp-2 [overflow-wrap:anywhere]">{video.name}</h2>
                    </div>
                    <Link href={`/watch/${video.id}`} draggable={false} className="mt-3 inline-flex h-11 items-center gap-2 rounded-full bg-primary px-4 text-sm font-semibold text-primary-foreground hover:bg-primary/90 lg:mt-5">
                        {playable ? <Play className="size-4 fill-current" /> : <Info className="size-4" />}
                        {playable ? (resumable ? 'Продолжить' : 'Смотреть') : 'Подробнее'}
                    </Link>
                </div>
            </div>
        </div>
    );
}

function SlideButton({ label, onClick, children, className }: { label: string; onClick: () => void; children: ReactNode; className?: string }) {
    return (
        <button
            type="button"
            aria-label={label}
            onClick={onClick}
            className={cn("inline-flex size-11 shrink-0 items-center justify-center rounded-full text-muted-foreground transition hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none", className)}
        >
            {children}
        </button>
    );
}
