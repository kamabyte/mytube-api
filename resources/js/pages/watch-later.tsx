import { Head } from '@inertiajs/react';
import { Clock3, ExternalLink } from 'lucide-react';
import { ChannelAvatar } from '@/components/channel-avatar';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { InfiniteVideoGrid } from '@/components/video-grid';
import { formatRelative } from '@/lib/format';
import type { Paginated, Video } from '@/types';

interface Props {
    enabled: boolean;
    lastSyncedAt: string | null;
    syncError: string | null;
    videos: Paginated<Video>;
}

const title = 'Смотреть позже';

export default function WatchLater({ enabled, lastSyncedAt, syncError, videos }: Props) {
    const status = !enabled
        ? 'Синхронизация выключена'
        : lastSyncedAt
            ? `Последнее обновление ${formatRelative(lastSyncedAt)}`
            : 'Ожидается первая синхронизация';

    return (
        <>
            <Head title={title} />

            <header className="mb-8 grid grid-cols-[auto_1fr] items-center gap-x-5 gap-y-4 rounded-3xl border border-border bg-gradient-to-br from-indigo-500/10 via-violet-500/5 to-transparent p-5 sm:grid-cols-[auto_1fr_auto] sm:p-7">
                <ChannelAvatar channel={{ name: title, thumbnail: null, is_watch_later: true }} className="size-16 sm:size-24" />
                <div className="min-w-0">
                    <h1 className="font-display text-2xl font-bold sm:text-4xl">{title}</h1>
                    <p className="mt-1 text-sm text-muted-foreground">{status}</p>
                </div>
                <Button asChild variant="secondary" className="col-start-2 justify-self-start rounded-full sm:col-start-3">
                    <a href="https://www.youtube.com/playlist?list=WL" target="_blank" rel="noreferrer">
                        <ExternalLink />
                        Открыть в YouTube
                    </a>
                </Button>
            </header>

            {syncError && (
                <p role="status" className="mb-6 rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm">
                    {syncError}
                </p>
            )}

            {videos.data.length > 0 ? (
                <InfiniteVideoGrid prop="videos" videos={videos.data} />
            ) : (
                <EmptyState icon={Clock3} title="Здесь появятся сохранённые видео">
                    {enabled
                        ? 'Добавь ролик в «Смотреть позже» в YouTube. После синхронизации он появится здесь и попадёт в обычную очередь загрузок.'
                        : 'Подключи сессию YouTube на сервере MyTube, чтобы импортировать список.'}
                </EmptyState>
            )}
        </>
    );
}
