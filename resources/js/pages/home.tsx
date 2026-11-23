import { Head, Link } from '@inertiajs/react';
import { History, ListChecks, Sparkles, Tv } from 'lucide-react';
import { toast } from 'sonner';
import { ChannelAvatar } from '@/components/channel-avatar';
import { EmptyState } from '@/components/empty-state';
import { Shelf } from '@/components/shelf';
import { Button } from '@/components/ui/button';
import { VideoCard } from '@/components/video-card';
import { channelVideoCounts } from '@/lib/format';
import { markAllWatched, markWatched, useContinueWatching } from '@/lib/watch-progress';
import type { ChannelSummary, Video } from '@/types';

interface Props {
    latest: Video[];
    shelves: ChannelSummary[];
}

export default function Home({ latest, shelves }: Props) {
    const continueWatching = useContinueWatching(latest);
    const markShelfWatched = () => {
        const undo = markAllWatched();
        if (!undo) return;

        toast.success('Все видео отмечены просмотренными', {
            duration: 8000,
            action: { label: 'Отменить', onClick: undo },
        });
    };

    if (latest.length === 0) {
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
                {continueWatching.length > 0 && (
                    <Shelf
                        title="Продолжить просмотр"
                        icon={<History className="size-5 shrink-0 text-brand" />}
                        actions={
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Отметить всё просмотренным"
                                title="Отметить всё просмотренным"
                                onClick={markShelfWatched}
                                className="size-11 rounded-full text-muted-foreground sm:h-9 sm:w-auto sm:px-3"
                            >
                                <ListChecks className="size-[18px]" aria-hidden="true" />
                                <span className="hidden sm:inline">Отметить всё</span>
                            </Button>
                        }
                    >
                        {continueWatching.map((entry) => (
                            <VideoCard
                                key={entry.video.id}
                                video={entry.video}
                                onMarkWatched={() => markWatched(entry.video, entry.duration)}
                            />
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
                        lazy
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
