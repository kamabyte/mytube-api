import { Ban, Clock, CloudDownload, ImageOff, Loader2, type LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { formatDuration } from '@/lib/format';
import { cn } from '@/lib/utils';
import { progressRatio, useProgressStore } from '@/lib/watch-progress';
import type { DownloadState, Video } from '@/types';

const STATE_BADGES: Partial<Record<DownloadState, { icon: LucideIcon; label: string; className: string }>> = {
    available: { icon: CloudDownload, label: 'Не скачано', className: 'bg-black/70 text-white' },
    queued: { icon: Clock, label: 'В очереди', className: 'bg-brand text-white' },
    downloading: { icon: Loader2, label: 'Скачивается', className: 'bg-brand text-white [&>svg]:animate-spin' },
    unavailable: { icon: Ban, label: 'Недоступно', className: 'bg-black/70 text-white' },
};

/** Превью 16:9 с длительностью и полосой просмотренного. */
export function VideoThumbnail({
    video,
    className,
    rounded = 'rounded-xl',
    sizes,
}: {
    video: Video;
    className?: string;
    rounded?: string;
    sizes?: string;
}) {
    const progress = progressRatio(useProgressStore()[video.id]);
    const [failed, setFailed] = useState(false);
    const badge = STATE_BADGES[video.download_state];

    return (
        <div className={cn('relative aspect-video w-full overflow-hidden bg-muted', rounded, className)}>
            {video.thumbnail && !failed ? (
                <img
                    src={video.thumbnail}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    sizes={sizes}
                    onError={() => setFailed(true)}
                    className={cn(
                        'size-full object-cover transition-transform duration-500 ease-out group-hover:scale-[1.04]',
                        // Каталог приглушён: видно, что смотреть пока нечего.
                        (video.download_state === 'available' || video.download_state === 'unavailable') && 'opacity-60 saturate-50',
                    )}
                />
            ) : (
                <div className="flex size-full items-center justify-center text-muted-foreground/60">
                    <ImageOff className="size-6" />
                </div>
            )}
            <div className="pointer-events-none absolute inset-0 rounded-[inherit] ring-1 ring-black/5 ring-inset dark:ring-white/5" />
            {badge && (
                <span
                    className={cn(
                        'absolute top-1.5 left-1.5 inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11.5px] font-semibold backdrop-blur-sm [&>svg]:size-3',
                        badge.className,
                    )}
                >
                    <badge.icon />
                    {badge.label}
                </span>
            )}
            {video.duration_seconds > 0 && (
                <span className="absolute right-1.5 bottom-1.5 rounded-md bg-black/75 px-1.5 py-0.5 text-[11.5px] font-semibold text-white tabular-nums backdrop-blur-sm">
                    {formatDuration(video.duration_seconds)}
                </span>
            )}
            {progress > 0 && (
                <div className="absolute inset-x-0 bottom-0 h-[3px] bg-white/30">
                    <div className="h-full bg-brand" style={{ width: `${Math.max(progress * 100, 4)}%` }} />
                </div>
            )}
        </div>
    );
}
