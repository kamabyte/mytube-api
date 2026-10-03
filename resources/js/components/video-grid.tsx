import { InfiniteScroll } from '@inertiajs/react';
import { VideoCard, VideoCardSkeleton } from '@/components/video-card';
import type { Video } from '@/types';

export const GRID_CLASS = 'grid grid-cols-1 gap-x-4 gap-y-8 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 min-[1900px]:grid-cols-5';

/** Сетка видео с бесконечной прокруткой по Inertia::scroll-пропу. */
export function InfiniteVideoGrid({ prop, videos, showChannel = true }: { prop: string; videos: Video[]; showChannel?: boolean }) {
    return (
        <InfiniteScroll
            data={prop}
            buffer={800}
            preserveUrl
            onlyNext
            className={GRID_CLASS}
            loading={() => (
                <>
                    {Array.from({ length: 4 }, (_, i) => (
                        <VideoCardSkeleton key={i} />
                    ))}
                </>
            )}
        >
            {videos.map((video) => (
                <VideoCard key={video.id} video={video} showChannel={showChannel} className="animate-fade-in" />
            ))}
        </InfiniteScroll>
    );
}
