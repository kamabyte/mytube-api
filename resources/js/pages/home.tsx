import { Head, Link } from '@inertiajs/react';
import { History, Sparkles, Tv } from 'lucide-react';
import { ChannelAvatar } from '@/components/channel-avatar';
import { EmptyState } from '@/components/empty-state';
import { Hero } from '@/components/hero';
import { Shelf } from '@/components/shelf';
import { VideoCard } from '@/components/video-card';
import { channelVideoCounts } from '@/lib/format';
import { useContinueWatching } from '@/lib/watch-progress';
import type { ChannelSummary, Video } from '@/types';

interface Props {
    featured: Video[];
    latest: Video[];
    shelves: ChannelSummary[];
}

export default function Home({ featured, latest, shelves }: Props) {
    const continueWatching = useContinueWatching();

    // Пусто — только если нет ни скачанного, ни каталога.
    if (featured.length === 0 && latest.length === 0) {
        return (
            <>
                <Head title="Главная" />
                <EmptyState icon={Tv} title="Пока пусто">
                    Видео появятся здесь, как только парсер заберёт списки с каналов.
                </EmptyState>
            </>
        );
    }

    return (
        <>
            <Head title="Главная" />
            <div className="flex flex-col gap-10 md:gap-12">
                {featured.length > 0 && <Hero videos={featured} />}

                {continueWatching.length > 0 && (
                    <Shelf title="Продолжить просмотр" icon={<History className="size-5 text-brand" />}>
                        {continueWatching.map((entry) => (
                            <VideoCard key={entry.video.id} video={entry.video} />
                        ))}
                    </Shelf>
                )}

                <Shelf title="Новые видео" href="/videos" icon={<Sparkles className="size-5 text-brand" />}>
                    {latest.map((video) => (
                        <VideoCard key={video.id} video={video} />
                    ))}
                </Shelf>

                {shelves.map((channel) => (
                    <Shelf
                        key={channel.id}
                        href={`/channels/${channel.id}`}
                        icon={<ChannelAvatar channel={channel} className="size-8" />}
                        title={
                            <span className="flex items-baseline gap-2">
                                <span className="truncate">{channel.name}</span>
                                {channel.videos_count !== undefined && (
                                    <span className="hidden shrink-0 text-sm font-normal text-muted-foreground sm:inline">
                                        {channelVideoCounts(channel)}
                                    </span>
                                )}
                            </span>
                        }
                    >
                        {(channel.videos ?? []).map((video) => (
                            <VideoCard key={video.id} video={video} showChannel={false} />
                        ))}
                    </Shelf>
                ))}

                <div className="flex justify-center">
                    <Link href="/channels" className="text-sm font-medium text-muted-foreground hover:text-foreground">
                        Все каналы →
                    </Link>
                </div>
            </div>
        </>
    );
}
