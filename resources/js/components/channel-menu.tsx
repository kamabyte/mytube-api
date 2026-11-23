import { router } from '@inertiajs/react';
import { CalendarPlus, CloudDownload, Ellipsis, ExternalLink, Hand, Pause, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { DeleteDialog } from '@/components/delete-dialog';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { ChannelSummary } from '@/types';

/** Сколько видео канала можно попросить: каталог минус скачанное и очередь. */
export function availableCount(channel: ChannelSummary): number {
    return Math.max((channel.catalog_count ?? 0) - (channel.videos_count ?? 0) - (channel.queued_count ?? 0), 0);
}

export function ChannelMenu({ channel, cardYoutubeUrl }: { channel: ChannelSummary; cardYoutubeUrl?: string }) {
    const [confirming, setConfirming] = useState(false);
    const [updating, setUpdating] = useState(false);
    const onCard = cardYoutubeUrl !== undefined;
    const kind = channel.is_playlist ? 'плейлист' : 'канал';

    // reset запрашивает свежую ленту и заменяет ранее загруженные страницы.
    const setMode = (mode: 'auto' | 'new' | 'on-demand' | 'paused') =>
        router.patch(
            `/channels/${channel.id}`,
            {
                download_on_demand: mode === 'on-demand' || mode === 'paused',
                download_new_only: mode === 'new',
                sync_paused: mode === 'paused',
            },
            {
                preserveScroll: true,
                ...(onCard
                    ? { only: ['channels', 'downloads'] }
                    : { only: ['channel', 'downloads', 'videos'], reset: ['videos'] }),
                onStart: () => setUpdating(true),
                onFinish: () => setUpdating(false),
            },
        );

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant={onCard ? 'ghost' : 'secondary'}
                        size="icon"
                        className={cn('size-11 rounded-full', onCard ? 'text-muted-foreground' : 'bg-background/60 backdrop-blur')}
                        aria-label={onCard ? `Действия: ${channel.name}` : 'Ещё'}
                        disabled={updating}
                    >
                        <Ellipsis className="size-5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-64 max-w-(--radix-dropdown-menu-content-available-width)">
                    {cardYoutubeUrl && (
                        <DropdownMenuItem asChild>
                            <a href={cardYoutubeUrl} target="_blank" rel="noreferrer">
                                <ExternalLink />
                                Открыть на YouTube
                            </a>
                        </DropdownMenuItem>
                    )}
                    {(channel.download_on_demand || channel.auto_download_from) && (
                        <DropdownMenuItem onSelect={() => setMode('auto')}>
                            <CloudDownload />
                            Скачивать всё
                        </DropdownMenuItem>
                    )}
                    {!channel.auto_download_from && (
                        <DropdownMenuItem onSelect={() => setMode('new')}>
                            <CalendarPlus />
                            Скачивать только новые
                        </DropdownMenuItem>
                    )}
                    {(!channel.download_on_demand || channel.sync_paused) && (
                        <DropdownMenuItem onSelect={() => setMode('on-demand')}>
                            <Hand />
                            Скачивать только по запросу
                        </DropdownMenuItem>
                    )}
                    {!channel.sync_paused && (
                        <DropdownMenuItem onSelect={() => setMode('paused')}>
                            <Pause />
                            Приостановить обновление
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuSeparator />
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
                onDeleted={() => setConfirming(false)}
            >
                <p>Из медиатеки будут удалены видео, которые есть только в этом источнике, и их заявки на загрузку.</p>
                <p>Отменить это нельзя. Добавить {kind} снова можно, но видео придётся скачивать заново.</p>
            </DeleteDialog>
        </>
    );
}
