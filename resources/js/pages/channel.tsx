import { Head, Link } from '@inertiajs/react';
import { Ellipsis, ListVideo, Play, Trash2, Video as VideoIcon } from 'lucide-react';
import { useState } from 'react';
import { ChannelAvatar } from '@/components/channel-avatar';
import { DeleteDialog } from '@/components/delete-dialog';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/empty-state';
import { SortControl } from '@/components/sort-control';
import { InfiniteVideoGrid } from '@/components/video-grid';
import { formatBytes, formatHours, formatRelative, plural } from '@/lib/format';
import type { ChannelSummary, Paginated, Video, VideoSort } from '@/types';

interface Props {
    channel: ChannelSummary;
    sort: VideoSort;
    videos: Paginated<Video>;
}

function ChannelMenu({ channel }: { channel: ChannelSummary }) {
    const [confirming, setConfirming] = useState(false);
    const kind = channel.is_playlist ? 'плейлист' : 'канал';

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="secondary" size="icon" className="size-11 rounded-full bg-background/60 backdrop-blur" aria-label="Ещё">
                        <Ellipsis className="size-5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    <DropdownMenuItem variant="destructive" onSelect={() => setConfirming(true)}>
                        <Trash2 />
                        Удалить {kind}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <DeleteDialog
                open={confirming}
                onOpenChange={setConfirming}
                url={`/channels/${channel.id}`}
                title={`Удалить ${kind} «${channel.name}»?`}
            >
                <p>
                    С диска будут удалены все скачанные видео
                    {channel.videos_count ? ` (${plural(channel.videos_count, ['видео', 'видео', 'видео'])}` : ''}
                    {channel.total_size_bytes ? `, ${formatBytes(channel.total_size_bytes)}` : ''}
                    {channel.videos_count ? ')' : ''}, а очередь загрузки — очищена.
                </p>
                <p>Отменить это нельзя. Добавить {kind} снова можно, но видео придётся скачивать заново.</p>
            </DeleteDialog>
        </>
    );
}

export default function Channel({ channel, sort, videos }: Props) {
    const first = videos.data[0];
    const stats = [
        plural(channel.videos_count ?? 0, ['видео', 'видео', 'видео']),
        channel.total_duration_seconds ? formatHours(channel.total_duration_seconds) : null,
        channel.total_size_bytes ? formatBytes(channel.total_size_bytes) : null,
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
                        {channel.is_playlist && (
                            <span className="mb-2 inline-flex items-center gap-1 rounded-full bg-background/60 px-2.5 py-0.5 text-xs font-medium backdrop-blur">
                                <ListVideo className="size-3.5" />
                                Плейлист
                            </span>
                        )}
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
                    <ChannelMenu channel={channel} />
                    </div>
                </div>
            </header>

            <div className="mb-6">
                <SortControl value={sort} />
            </div>

            {videos.data.length === 0 ? (
                <EmptyState icon={VideoIcon} title="Скачанных видео пока нет">
                    {channel.queued_count
                        ? `В очереди ${plural(channel.queued_count, ['видео', 'видео', 'видео'])} — воркер скачает их по очереди, загляните позже.`
                        : 'Список видео обновляется раз в 10 минут, потом воркер скачает их по очереди.'}
                </EmptyState>
            ) : (
                <InfiniteVideoGrid prop="videos" videos={videos.data} showChannel={false} />
            )}
        </>
    );
}
