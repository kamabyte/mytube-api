import { Head } from '@inertiajs/react';
import { Clapperboard } from 'lucide-react';
import { EmptyState, PageTitle } from '@/components/empty-state';
import { SortControl } from '@/components/sort-control';
import { InfiniteVideoGrid } from '@/components/video-grid';
import { plural } from '@/lib/format';
import type { Paginated, Video, VideoSort } from '@/types';

export default function Feed({ videos, sort }: { videos: Paginated<Video>; sort: VideoSort }) {
    return (
        <>
            <Head title="Все видео" />
            <PageTitle actions={<SortControl value={sort} />}>
                Все видео
                <span className="ml-3 align-middle text-base font-normal text-muted-foreground">
                    {plural(videos.meta.total, ['видео', 'видео', 'видео'])}
                </span>
            </PageTitle>
            {videos.data.length === 0 ? (
                <EmptyState icon={Clapperboard} title="Видео ещё нет" />
            ) : (
                <InfiniteVideoGrid prop="videos" videos={videos.data} />
            )}
        </>
    );
}
