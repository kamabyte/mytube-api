import { Link } from '@inertiajs/react';
import { ChannelAvatar } from '@/components/channel-avatar';
import { VideoThumbnail } from '@/components/video-thumbnail';
import { formatRelative, formatViews } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Video } from '@/types';

function Meta({ video }: { video: Video }) {
    const parts = [video.view_count > 0 ? formatViews(video.view_count) : null, formatRelative(video.published_at)].filter(
        Boolean,
    );

    return <span className="truncate">{parts.join(' · ')}</span>;
}

/** Карточка в сетке и на полках. */
export function VideoCard({
    video,
    showChannel = true,
    className,
}: {
    video: Video;
    showChannel?: boolean;
    className?: string;
}) {
    return (
        <article className={cn('group relative flex flex-col gap-3', className)}>
            <Link href={`/watch/${video.id}`} className="rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none">
                <VideoThumbnail
                    video={video}
                    className="shadow-sm transition-shadow duration-300 group-hover:shadow-lg group-hover:shadow-black/10 dark:group-hover:shadow-black/40"
                />
                <span className="sr-only">{video.name}</span>
            </Link>
            <div className="flex gap-3">
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
            </div>
        </article>
    );
}

/** Строка для колонки «Далее» и компактных списков. */
export function VideoRow({ video, active, className }: { video: Video; active?: boolean; className?: string }) {
    return (
        <Link
            href={`/watch/${video.id}`}
            className={cn(
                'group flex gap-3 rounded-xl p-1.5 transition-colors hover:bg-accent/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                active && 'bg-accent',
                className,
            )}
        >
            <VideoThumbnail video={video} className="w-40 shrink-0 sm:w-44" rounded="rounded-lg" />
            <div className="min-w-0 flex-1 py-0.5">
                <h4 className="line-clamp-2 text-sm leading-snug font-semibold">{video.name}</h4>
                <div className="mt-1 flex flex-col text-xs leading-5 text-muted-foreground">
                    {video.channel && <span className="truncate">{video.channel.name}</span>}
                    <Meta video={video} />
                </div>
            </div>
        </Link>
    );
}

export function VideoCardSkeleton() {
    return (
        <div className="flex flex-col gap-3">
            <div className="aspect-video animate-pulse rounded-xl bg-muted" />
            <div className="flex gap-3">
                <div className="size-9 animate-pulse rounded-full bg-muted" />
                <div className="flex-1 space-y-2 pt-1">
                    <div className="h-3.5 w-11/12 animate-pulse rounded bg-muted" />
                    <div className="h-3 w-2/3 animate-pulse rounded bg-muted" />
                </div>
            </div>
        </div>
    );
}
