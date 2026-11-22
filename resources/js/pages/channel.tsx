import { Head, Link } from '@inertiajs/react';
import { ExternalLink, Hand, ListVideo, Pause, Play, Video as VideoIcon } from 'lucide-react';
import { ChannelAvatar } from '@/components/channel-avatar';
import { availableCount, ChannelMenu } from '@/components/channel-menu';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/empty-state';
import { SortControl } from '@/components/sort-control';
import { InfiniteVideoGrid } from '@/components/video-grid';
import { formatBytes, formatHours, formatRelative, plural } from '@/lib/format';
import type { ChannelSummary, Paginated, Video, VideoSort } from '@/types';

interface Props {
    channel: ChannelSummary;
    youtubeUrl: string;
    sort: VideoSort;
    videos: Paginated<Video>;
}

export default function Channel({ channel, youtubeUrl, sort, videos }: Props) {
    // В ленте теперь и каталог — «Смотреть» начинает со скачанного.
    const first = videos.data.find((video) => video.download_state === 'downloaded');
    const available = availableCount(channel);
    const downloaded = channel.videos_count ?? 0;
    const stats = [
        // Ничего не скачано и есть каталог — «0 видео» не пишем.
        downloaded > 0 || available === 0 ? plural(downloaded, ['видео', 'видео', 'видео']) : null,
        channel.total_duration_seconds ? formatHours(channel.total_duration_seconds) : null,
        channel.total_size_bytes ? formatBytes(channel.total_size_bytes) : null,
        available > 0
            ? downloaded > 0
                ? `ещё ${available} можно скачать`
                : `${plural(available, ['видео', 'видео', 'видео'])} можно скачать`
            : null,
    ].filter(Boolean);

    return (
        <>
            <Head title={channel.name} />

            <header className="relative isolate -mx-4 -mt-4 mb-8 overflow-hidden px-4 pt-10 pb-8 md:-mx-6 md:-mt-6 md:px-6 md:pt-14 lg:-mx-8 lg:px-8">
                {channel.thumbnail && (
                    <img
                        src={channel.thumbnail}
                        alt=""
                        aria-hidden
                        className="absolute inset-0 -z-10 size-full scale-125 object-cover opacity-40 blur-3xl saturate-150 dark:opacity-35"
                    />
                )}
                <div className="absolute inset-0 -z-10 bg-gradient-to-b from-transparent via-background/40 to-background" />

                <div className="flex flex-col items-center gap-5 text-center sm:flex-row sm:items-end sm:text-left">
                    <ChannelAvatar channel={channel} className="size-28 text-2xl shadow-2xl ring-4 ring-background/60 md:size-36" />
                    <div className="min-w-0 flex-1">
                        <div className="mb-2 flex flex-wrap justify-center gap-1.5 empty:hidden sm:justify-start">
                            {channel.is_playlist && (
                                <span className="inline-flex items-center gap-1 rounded-full bg-background/60 px-2.5 py-0.5 text-xs font-medium backdrop-blur">
                                    <ListVideo className="size-3.5" />
                                    Плейлист
                                </span>
                            )}
                            {channel.sync_paused ? (
                                <span className="inline-flex items-center gap-1 rounded-full bg-background/60 px-2.5 py-0.5 text-xs font-medium backdrop-blur">
                                    <Pause className="size-3.5" />
                                    На паузе
                                </span>
                            ) : channel.download_on_demand && (
                                <span className="inline-flex items-center gap-1 rounded-full bg-background/60 px-2.5 py-0.5 text-xs font-medium backdrop-blur">
                                    <Hand className="size-3.5" />
                                    По запросу
                                </span>
                            )}
                        </div>
                        <h1 className="font-display text-3xl font-bold text-balance md:text-5xl">{channel.name}</h1>
                        <p className="mt-2 text-sm text-muted-foreground md:text-base">
                            {stats.join(' · ')}
                            {channel.latest_published_at && <> · обновлён {formatRelative(channel.latest_published_at)}</>}
                            {!!channel.queued_count && <> · в очереди {channel.queued_count}</>}
                        </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                    {first && (
                        <Link
                            href={`/watch/${first.id}`}
                            className="inline-flex h-11 shrink-0 items-center gap-2 rounded-full bg-foreground px-6 text-[15px] font-semibold text-background shadow-lg transition hover:scale-[1.03] active:scale-100"
                        >
                            <Play className="size-4 fill-current" />
                            Смотреть
                        </Link>
                    )}
                    <Button asChild variant="secondary" className="h-11 rounded-full bg-background/60 backdrop-blur">
                        <a href={youtubeUrl} target="_blank" rel="noreferrer">
                            <ExternalLink />
                            YouTube
                        </a>
                    </Button>
                    <ChannelMenu channel={channel} />
                    </div>
                </div>
            </header>

            <div className="mb-6">
                <SortControl value={sort} />
            </div>

            {videos.data.length === 0 ? (
                <EmptyState icon={VideoIcon} title="Видео пока нет">
                    {channel.sync_paused
                        ? 'Обновление каталога приостановлено. Изменить режим можно в меню канала.'
                        : channel.download_on_demand
                        ? 'Список видео обновляется раз в минуту. Скачивается только то, что вы попросите.'
                        : 'Список видео обновляется раз в минуту, потом воркер скачает их по очереди.'}
                </EmptyState>
            ) : (
                <InfiniteVideoGrid prop="videos" videos={videos.data} showChannel={false} />
            )}
        </>
    );
}
