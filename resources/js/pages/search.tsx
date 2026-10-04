import { Head, Link } from '@inertiajs/react';
import { SearchX } from 'lucide-react';
import { ChannelAvatar } from '@/components/channel-avatar';
import { EmptyState } from '@/components/empty-state';
import { SearchBox } from '@/components/search-box';
import { InfiniteVideoGrid } from '@/components/video-grid';
import { channelVideoCounts, plural } from '@/lib/format';
import type { ChannelSummary, Paginated, Video } from '@/types';

interface Props {
    query: string;
    channels: ChannelSummary[];
    videos: Paginated<Video> | null;
}

export default function Search({ query, channels, videos }: Props) {
    const nothing = query !== '' && channels.length === 0 && (videos?.data.length ?? 0) === 0;

    return (
        <>
            <Head title={query ? `${query} — поиск` : 'Поиск'} />

            <SearchBox className="mb-6 md:hidden" autoFocus={!query} />

            {query === '' ? (
                <div className="py-16 text-center text-muted-foreground">
                    <p className="font-display text-2xl font-semibold text-foreground">Что ищем?</p>
                    <p className="mt-2 text-sm">Название видео или канала. Нажмите «/», чтобы сразу перейти к поиску.</p>
                </div>
            ) : nothing ? (
                <EmptyState icon={SearchX} title="Ничего не нашлось">
                    По запросу «{query}» нет ни видео, ни каналов.
                </EmptyState>
            ) : (
                <div className="flex flex-col gap-10">
                    <h1 className="font-display text-2xl font-bold md:text-3xl">
                        «{query}»
                        {videos && (
                            <span className="ml-3 align-middle text-base font-normal text-muted-foreground">
                                {plural(videos.meta.total, ['видео', 'видео', 'видео'])}
                            </span>
                        )}
                    </h1>

                    {channels.length > 0 && (
                        <section>
                            <h2 className="mb-3 text-sm font-semibold tracking-wide text-muted-foreground uppercase">Каналы</h2>
                            <div className="flex flex-wrap gap-3">
                                {channels.map((channel) => (
                                    <Link
                                        key={channel.id}
                                        href={`/channels/${channel.id}`}
                                        className="flex items-center gap-3 rounded-2xl border border-border/60 bg-card py-2.5 pr-5 pl-2.5 transition hover:bg-accent"
                                    >
                                        <ChannelAvatar channel={channel} className="size-11" />
                                        <div>
                                            <div className="font-semibold">{channel.name}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {channelVideoCounts(channel)}
                                            </div>
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        </section>
                    )}

                    {videos && videos.data.length > 0 && (
                        <section>
                            <h2 className="mb-4 text-sm font-semibold tracking-wide text-muted-foreground uppercase">Видео</h2>
                            <InfiniteVideoGrid prop="videos" videos={videos.data} />
                        </section>
                    )}
                </div>
            )}
        </>
    );
}
