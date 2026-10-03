import { Link } from '@inertiajs/react';
import { Play, Tv } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ChannelAvatar } from '@/components/channel-avatar';
import { formatDuration, formatRelative } from '@/lib/format';
import { cn } from '@/lib/utils';
import { progressRatio, useProgressStore } from '@/lib/watch-progress';
import type { Video } from '@/types';

const INTERVAL = 8000;

/** Витрина вверху главной — как баннер в Apple TV: крупный кадр, заголовок, «Смотреть». */
export function Hero({ videos }: { videos: Video[] }) {
    const [index, setIndex] = useState(0);
    const [paused, setPaused] = useState(false);
    const progress = useProgressStore();

    useEffect(() => {
        if (paused || videos.length < 2) return;
        const timer = window.setTimeout(() => setIndex((i) => (i + 1) % videos.length), INTERVAL);
        return () => window.clearTimeout(timer);
    }, [index, paused, videos.length]);

    if (videos.length === 0) return null;

    const current = videos[index];
    const ratio = progressRatio(progress[current.id]);
    const resumable = ratio > 0.02 && ratio < 1;

    const fresh = current.downloaded_at && Date.now() - new Date(current.downloaded_at).getTime() < 3 * 86400_000;

    return (
        <section
            className="relative isolate overflow-hidden rounded-3xl bg-zinc-900 text-white shadow-xl shadow-black/10 dark:shadow-black/40"
            onMouseEnter={() => setPaused(true)}
            onMouseLeave={() => setPaused(false)}
            onFocusCapture={() => setPaused(true)}
            onBlurCapture={() => setPaused(false)}
            aria-roledescription="карусель"
        >
            {/* Фон — размытая обложка: превью у YouTube мелкие (320×180), во весь баннер они мылят. */}
            {videos.map((video, i) =>
                video.thumbnail ? (
                    <img
                        key={video.id}
                        src={video.thumbnail}
                        alt=""
                        aria-hidden
                        className={cn(
                            'absolute inset-0 -z-10 size-full scale-125 object-cover blur-2xl saturate-[1.4] transition-opacity duration-1000',
                            i === index ? 'opacity-80' : 'opacity-0',
                        )}
                    />
                ) : null,
            )}
            <div className="absolute inset-0 -z-10 bg-gradient-to-t from-black/80 via-black/45 to-black/25 lg:bg-gradient-to-r lg:from-black/80 lg:via-black/50 lg:to-black/20" />

            <div className="grid items-center gap-6 p-5 sm:p-8 lg:grid-cols-[1fr_minmax(0,460px)] lg:gap-10 lg:p-12 xl:grid-cols-[1fr_minmax(0,520px)]">
                <Link
                    href={`/watch/${current.id}`}
                    className="group relative block overflow-hidden rounded-2xl shadow-2xl ring-1 shadow-black/50 ring-white/10 lg:order-2"
                    tabIndex={-1}
                    aria-hidden
                >
                    <div className="relative aspect-video">
                        {videos.map((video, i) =>
                            video.thumbnail ? (
                                <img
                                    key={video.id}
                                    src={video.thumbnail}
                                    alt=""
                                    fetchPriority={i === 0 ? 'high' : 'low'}
                                    className={cn(
                                        'absolute inset-0 size-full object-cover transition-[opacity,transform] duration-700 ease-out group-hover:scale-[1.03]',
                                        i === index ? 'opacity-100' : 'opacity-0',
                                    )}
                                />
                            ) : null,
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

                <div key={current.id} className="animate-fade-in flex min-w-0 flex-col gap-3 lg:order-1">
                    {current.channel && (
                        <Link href={`/channels/${current.channel.id}`} className="flex w-fit items-center gap-2 text-sm font-medium text-white/85 hover:text-white">
                            <ChannelAvatar channel={current.channel} className="size-6 ring-white/20" />
                            {current.channel.name}
                        </Link>
                    )}
                    <h2 className="font-display line-clamp-3 text-2xl leading-tight font-bold text-balance sm:text-4xl xl:text-[44px]">
                        {current.name}
                    </h2>
                    <div className="flex items-center gap-2 text-sm text-white/70">
                        {fresh && <span className="rounded-md bg-white/15 px-1.5 py-0.5 text-xs font-semibold text-white backdrop-blur">Новинка</span>}
                        {current.duration_seconds > 0 && <span className="tabular-nums">{formatDuration(current.duration_seconds)}</span>}
                        {current.published_at && <span>· {formatRelative(current.published_at)}</span>}
                    </div>
                    <div className="mt-2 flex flex-wrap items-center gap-3">
                        <Link
                            href={`/watch/${current.id}`}
                            className="inline-flex h-11 items-center gap-2 rounded-full bg-white px-6 text-[15px] font-semibold text-black shadow-lg transition hover:scale-[1.03] hover:bg-white/90 active:scale-100"
                        >
                            <Play className="size-4 fill-current" />
                            {resumable ? 'Продолжить' : 'Смотреть'}
                        </Link>
                        {current.channel && (
                            <Link
                                href={`/channels/${current.channel.id}`}
                                className="inline-flex h-11 items-center gap-2 rounded-full bg-white/15 px-5 text-[15px] font-semibold backdrop-blur-md transition hover:bg-white/25"
                            >
                                <Tv className="size-4" />
                                Канал
                            </Link>
                        )}
                    </div>

                    {videos.length > 1 && (
                        <div className="mt-4 flex gap-1.5" role="tablist" aria-label="Слайды">
                            {videos.map((video, i) => (
                                <button
                                    key={video.id}
                                    role="tab"
                                    aria-selected={i === index}
                                    aria-label={video.name}
                                    onClick={() => setIndex(i)}
                                    className="group/dot flex h-6 items-center"
                                >
                                    <span
                                        className={cn(
                                            'block h-1.5 rounded-full bg-white/35 transition-all duration-500 group-hover/dot:bg-white/70',
                                            i === index ? 'w-6 bg-white' : 'w-1.5',
                                        )}
                                    />
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </section>
    );
}
