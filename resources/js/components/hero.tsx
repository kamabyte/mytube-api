import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Pause, Play, Tv } from 'lucide-react';
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
            aria-label="Рекомендуемые видео"
            aria-roledescription="карусель"
            className="relative isolate overflow-hidden rounded-3xl bg-zinc-900 text-white shadow-xl shadow-black/10 dark:shadow-black/40"
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
                            controls={loop}
                        />
                    );
                })}
            </div>

            {loop && (
                <div className="absolute bottom-5 left-5 flex items-center gap-3 sm:bottom-8 sm:left-8 lg:bottom-12 lg:left-12">
                    <SlideButton label="Предыдущий слайд" onClick={() => navigate(-1)}>
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
                                className="group/dot flex h-9 items-center rounded-full px-1 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                            >
                                <span className={cn(
                                    'block h-2.5 rounded-full transition-[width,background-color] duration-200 motion-reduce:transition-none',
                                    i === index ? 'w-8 bg-white' : 'w-2.5 bg-white/40 group-hover/dot:bg-white/75',
                                )} />
                            </button>
                        ))}
                    </div>
                    <SlideButton label="Следующий слайд" onClick={() => navigate(1)}>
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

function HeroSlide({ video, position, label, selected, first, ratio, controls }: {
    video: Video;
    position: number;
    label: string;
    selected: boolean;
    first: boolean;
    ratio: number;
    controls: boolean;
}) {
    const resumable = ratio > 0.02 && ratio < 1;
    const fresh = video.downloaded_at && Date.now() - new Date(video.downloaded_at).getTime() < 3 * 86400_000;

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
            {video.thumbnail && (
                <img
                    src={video.thumbnail}
                    alt=""
                    aria-hidden
                    draggable={false}
                    decoding="async"
                    className="absolute inset-0 -z-10 size-full scale-125 object-cover opacity-80 blur-2xl saturate-[1.4]"
                />
            )}
            <div className="absolute inset-0 -z-10 bg-gradient-to-t from-black/80 via-black/45 to-black/25 lg:bg-gradient-to-r lg:from-black/80 lg:via-black/50 lg:to-black/20" />
            <div className={cn(
                'grid h-full items-center gap-6 p-5 sm:p-8 lg:grid-cols-[1fr_minmax(0,460px)] lg:gap-10 lg:p-12 xl:grid-cols-[1fr_minmax(0,520px)]',
                controls && 'pb-24 sm:pb-28 lg:pb-32',
            )}>
                <Link
                    href={`/watch/${video.id}`}
                    className="group relative block overflow-hidden rounded-2xl shadow-2xl ring-1 shadow-black/50 ring-white/10 lg:order-2"
                    tabIndex={-1}
                    aria-hidden
                    draggable={false}
                >
                    <div className="relative aspect-video">
                        {video.thumbnail && (
                            <img
                                src={video.thumbnail}
                                alt=""
                                draggable={false}
                                decoding="async"
                                fetchPriority={first ? 'high' : 'low'}
                                className="absolute inset-0 size-full object-cover transition-transform duration-300 ease-out group-hover:scale-[1.03] motion-reduce:transition-none"
                            />
                        )}
                        <span className="absolute inset-0 flex items-center justify-center bg-black/0 transition group-hover:bg-black/20">
                            <span className="flex size-14 scale-90 items-center justify-center rounded-full bg-white/90 text-black opacity-0 shadow-xl transition group-hover:scale-100 group-hover:opacity-100">
                                <Play className="size-6 translate-x-0.5 fill-current" />
                            </span>
                        </span>
                        {resumable && (
                            <div className="absolute inset-x-0 bottom-0 h-1 bg-white/30">
                                <div className="h-full bg-brand" style={{ width: `${ratio * 100}%` }} />
                            </div>
                        )}
                    </div>
                </Link>
                <div className="flex min-w-0 flex-col gap-3 lg:order-1">
                    <div className="h-6">
                        {video.channel && (
                            <Link href={`/channels/${video.channel.id}`} className="flex max-w-full items-center gap-2 text-sm font-medium text-white/85 hover:text-white" draggable={false}>
                                <ChannelAvatar channel={video.channel} className="size-6 shrink-0 ring-white/20" />
                                <span className="truncate">{video.channel.name}</span>
                            </Link>
                        )}
                    </div>
                    <div className="font-display flex min-h-[3lh] items-end text-2xl leading-tight font-bold sm:text-4xl xl:text-[44px]">
                        <h2 className="line-clamp-3 text-balance">{video.name}</h2>
                    </div>
                    <div className="flex h-6 items-center gap-2 text-sm text-white/70">
                        {fresh && <span className="rounded-md bg-white/15 px-1.5 py-0.5 text-xs font-semibold text-white">Новинка</span>}
                        {video.duration_seconds > 0 && <span className="tabular-nums">{formatDuration(video.duration_seconds)}</span>}
                        {video.published_at && <span>· {formatRelative(video.published_at)}</span>}
                    </div>
                    <div className="mt-2 flex flex-wrap items-center gap-3">
                        <Link href={`/watch/${video.id}`} draggable={false} className="inline-flex h-11 items-center gap-2 rounded-full bg-white px-6 text-[15px] font-semibold text-black shadow-lg transition hover:scale-[1.03] hover:bg-white/90 active:scale-100">
                            <Play className="size-4 fill-current" />
                            {resumable ? 'Продолжить' : 'Смотреть'}
                        </Link>
                        {video.channel && (
                            <Link href={`/channels/${video.channel.id}`} draggable={false} className="inline-flex h-11 items-center gap-2 rounded-full bg-white/15 px-5 text-[15px] font-semibold transition hover:bg-white/25">
                                <Tv className="size-4" />
                                Канал
                            </Link>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

function SlideButton({ label, onClick, children }: { label: string; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            aria-label={label}
            onClick={onClick}
            className="flex size-9 shrink-0 items-center justify-center rounded-full bg-white/15 transition hover:bg-white/25 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none active:scale-95"
        >
            {children}
        </button>
    );
}
