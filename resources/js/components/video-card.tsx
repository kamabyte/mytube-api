import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { ChannelAvatar } from '@/components/channel-avatar';
import { VideoActions } from '@/components/video-actions';
import { VideoThumbnail } from '@/components/video-thumbnail';
import { formatRelative, formatViews } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { DownloadState, Video } from '@/types';

function Meta({ video }: { video: Video }) {
    const parts = [video.view_count > 0 ? formatViews(video.view_count) : null, formatRelative(video.published_at)].filter(
        Boolean,
    );

    return <span className="truncate">{parts.join(' · ')}</span>;
}

function useVideoOverride(initial: Video) {
    // Что поменяли из меню — поверх данных страницы, без её перезагрузки.
    const [override, setOverride] = useState<DownloadState | 'removed' | null>(null);
    const video = override === 'removed' ? null : override ? {
        ...initial,
        download_state: override,
        download_requested_at: override === 'queued' ? new Date().toISOString() : null,
        auto_download: false,
    } : initial;

    return [video, setOverride] as const;
}

/** Карточка в сетке и на полках. */
export function VideoCard({
    video: initial,
    showChannel = true,
    className,
    onMarkWatched,
}: {
    video: Video;
    showChannel?: boolean;
    className?: string;
    onMarkWatched?: () => void;
}) {
    const [video, setOverride] = useVideoOverride(initial);
    if (!video) return null;

    return (
        <article className={cn(
            'group relative flex flex-col gap-3',
            className,
            video.download_state === 'downloading' && 'animate-none opacity-80',
        )}>
            <Link href={`/watch/${video.id}`} className="rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none">
                <VideoThumbnail
                    video={video}
                    className="shadow-sm transition-shadow duration-300 group-hover:shadow-lg group-hover:shadow-black/10 dark:group-hover:shadow-black/40"
                />
                <span className="sr-only">{video.name}</span>
            </Link>
            {onMarkWatched && (
                <button
                    type="button"
                    aria-label="Отметить просмотренным"
                    title="Отметить просмотренным"
                    onClick={onMarkWatched}
                    className="group/watched absolute top-0 right-0 grid size-11 place-items-center rounded-xl text-white focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <span className="grid size-7 place-items-center rounded-lg bg-black/70 transition-colors group-hover/watched:bg-black/90">
                        <Check className="size-4" aria-hidden="true" />
                    </span>
                </button>
            )}
            <div className="flex gap-2 sm:gap-3">
                {showChannel && video.channel && (
                    <Link href={`/channels/${video.channel.id}`} className="mt-0.5 shrink-0" tabIndex={-1} aria-hidden>
                        <ChannelAvatar channel={video.channel} className="size-9" />
                    </Link>
                )}
                <div className="min-w-0 flex-1">
                    <Link href={`/watch/${video.id}`} tabIndex={-1}>
                        <h3 className="line-clamp-2 text-[15px] leading-snug font-semibold text-foreground">{video.name}</h3>
                    </Link>
                    <div className="mt-1 flex flex-col text-[13px] leading-5 text-muted-foreground">
                        {showChannel && video.channel && (
                            <Link href={`/channels/${video.channel.id}`} className="truncate hover:text-foreground">
                                {video.channel.name}
                            </Link>
                        )}
                        <Meta video={video} />
                    </div>
                </div>
                <VideoActions video={video} onChange={setOverride} className="-mt-1 -mr-2" />
            </div>
        </article>
    );
}

/** Строка для колонки «Далее» и компактных списков. */
export function VideoRow({ video: initial, active, className }: { video: Video; active?: boolean; className?: string }) {
    const [video, setOverride] = useVideoOverride(initial);
    if (!video) return null;

    return (
        <div
            className={cn(
                'flex items-start gap-1 rounded-xl p-1.5 transition-colors hover:bg-accent/60',
                active && 'bg-accent',
                className,
            )}
        >
            <Link
                href={`/watch/${video.id}`}
                className="flex min-w-0 flex-1 items-start gap-3 rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <VideoThumbnail video={video} className="w-28 shrink-0 min-[360px]:w-40 sm:w-44" rounded="rounded-lg" />
                <div className="min-w-0 flex-1 py-0.5">
                    <h4 className="line-clamp-2 text-sm leading-snug font-semibold">{video.name}</h4>
                    <div className="mt-1 flex flex-col text-xs leading-5 text-muted-foreground">
                        {video.channel && <span className="truncate">{video.channel.name}</span>}
                        <Meta video={video} />
                    </div>
                </div>
            </Link>
            <VideoActions video={video} onChange={setOverride} />
        </div>
    );
}

export function VideoCardSkeleton() {
    return (
        <div className="flex flex-col gap-3">
            <div className="aspect-video animate-pulse rounded-xl bg-muted" />
            <div className="flex gap-2 sm:gap-3">
                <div className="size-9 animate-pulse rounded-full bg-muted" />
                <div className="flex-1 space-y-2 pt-1">
                    <div className="h-3.5 w-11/12 animate-pulse rounded bg-muted" />
                    <div className="h-3 w-2/3 animate-pulse rounded bg-muted" />
                </div>
            </div>
        </div>
    );
}
