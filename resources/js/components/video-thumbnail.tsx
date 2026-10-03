import { ImageOff } from 'lucide-react';
import { useState } from 'react';
import { formatDuration } from '@/lib/format';
import { cn } from '@/lib/utils';
import { progressRatio, useProgressStore } from '@/lib/watch-progress';
import type { Video } from '@/types';

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
                    className="size-full object-cover transition-transform duration-500 ease-out group-hover:scale-[1.04]"
                />
            ) : (
                <div className="flex size-full items-center justify-center text-muted-foreground/60">
                    <ImageOff className="size-6" />
                </div>
            )}
            <div className="pointer-events-none absolute inset-0 rounded-[inherit] ring-1 ring-black/5 ring-inset dark:ring-white/5" />
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
