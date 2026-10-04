import { Link, router } from '@inertiajs/react';
import { CloudDownload, EllipsisVertical, FileX, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { ChannelAvatar } from '@/components/channel-avatar';
import { DeleteDialog } from '@/components/delete-dialog';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { VideoThumbnail } from '@/components/video-thumbnail';
import { formatRelative, formatViews } from '@/lib/format';
import { cn } from '@/lib/utils';
import { removeProgress } from '@/lib/watch-progress';
import type { DownloadState, Video } from '@/types';

/**
 * После действия из меню перезагружаем только состояние PIN: полная перезагрузка
 * сбросила бы бесконечную ленту, а саму карточку меню обновляет сразу.
 */
const RELOAD_ONLY = ['deletePin'];

/** Меню «⋮» карточки: скачать, отменить, убрать файл, удалить видео. */
function VideoActions({ video, onChange }: { video: Video; onChange: (next: DownloadState | 'removed') => void }) {
    const [dialog, setDialog] = useState<'file' | 'delete' | null>(null);
    const state = video.download_state;
    const canCancel = state === 'queued' && !!video.download_requested_at && !video.auto_download;

    const request = (method: 'post' | 'delete', next: DownloadState) =>
        router.visit(`/videos/${video.id}/download`, {
            method,
            preserveScroll: true,
            preserveState: true,
            only: RELOAD_ONLY,
            onSuccess: () => onChange(next),
        });

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Действия с видео"
                        className="-mt-1 -mr-2 size-8 shrink-0 rounded-full text-muted-foreground md:opacity-0 md:group-hover:opacity-100 md:focus-visible:opacity-100 md:data-[state=open]:opacity-100"
                    >
                        <EllipsisVertical className="size-[18px]" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-64">
                    {state === 'available' && (
                        <DropdownMenuItem onSelect={() => request('post', 'queued')}>
                            <CloudDownload />
                            Скачать в медиатеку
                        </DropdownMenuItem>
                    )}
                    {canCancel && (
                        <DropdownMenuItem onSelect={() => request('delete', 'available')}>
                            <X />
                            Отменить загрузку
                        </DropdownMenuItem>
                    )}
                    {state === 'downloaded' && (
                        <DropdownMenuItem disabled={video.auto_download} onSelect={() => setDialog('file')} className="items-start">
                            <FileX className="mt-0.5" />
                            <span>
                                Удалить файл
                                <span className="block text-xs text-muted-foreground">
                                    {video.auto_download ? 'Канал скачивается целиком — файл скачался бы снова' : 'Видео останется в каталоге'}
                                </span>
                            </span>
                        </DropdownMenuItem>
                    )}
                    {(state === 'available' || canCancel || state === 'downloaded') && <DropdownMenuSeparator />}
                    <DropdownMenuItem variant="destructive" onSelect={() => setDialog('delete')}>
                        <Trash2 />
                        Удалить видео
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <DeleteDialog
                open={dialog === 'file'}
                onOpenChange={(open) => setDialog(open ? 'file' : null)}
                url={`/videos/${video.id}/file`}
                title="Удалить файл видео?"
                confirmLabel="Удалить файл"
                only={RELOAD_ONLY}
                onDeleted={() => {
                    setDialog(null);
                    onChange('available');
                }}
            >
                <p>«{video.name}» останется в каталоге с обложкой, а файл будет удалён с диска.</p>
                <p>Чтобы посмотреть его, видео нужно будет скачать снова.</p>
            </DeleteDialog>

            <DeleteDialog
                open={dialog === 'delete'}
                onOpenChange={(open) => setDialog(open ? 'delete' : null)}
                url={`/videos/${video.id}`}
                title="Удалить видео?"
                only={RELOAD_ONLY}
                onDeleted={() => {
                    removeProgress(video.id);
                    onChange('removed');
                }}
            >
                <p>
                    «{video.name}» пропадёт из каталога{state === 'downloaded' ? ', файл будет удалён с диска' : ''}.
                </p>
                <p>Скачиваться снова это видео не будет, даже когда канал обновится.</p>
            </DeleteDialog>
        </>
    );
}

function Meta({ video }: { video: Video }) {
    const parts = [video.view_count > 0 ? formatViews(video.view_count) : null, formatRelative(video.published_at)].filter(
        Boolean,
    );

    return <span className="truncate">{parts.join(' · ')}</span>;
}

/** Карточка в сетке и на полках. */
export function VideoCard({
    video: initial,
    showChannel = true,
    className,
}: {
    video: Video;
    showChannel?: boolean;
    className?: string;
}) {
    // Что поменяли из меню — поверх данных страницы, без её перезагрузки.
    const [override, setOverride] = useState<DownloadState | 'removed' | null>(null);

    if (override === 'removed') return null;

    const video = override
        ? { ...initial, download_state: override, download_requested_at: override === 'queued' ? new Date().toISOString() : null }
        : initial;

    return (
        <article className={cn('group relative flex flex-col gap-3', className)}>
            <Link href={`/watch/${video.id}`} className="rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none">
                <VideoThumbnail
                    video={video}
                    className="shadow-sm transition-shadow duration-300 group-hover:shadow-lg group-hover:shadow-black/10 dark:group-hover:shadow-black/40"
                />
                <span className="sr-only">{video.name}</span>
            </Link>
            <div className="flex gap-3">
                {showChannel && video.channel && (
                    <Link href={`/channels/${video.channel.id}`} className="mt-0.5 shrink-0" tabIndex={-1} aria-hidden>
                        <ChannelAvatar channel={video.channel} className="size-9" />
                    </Link>
                )}
                <div className="min-w-0 flex-1">
                    <Link href={`/watch/${video.id}`} tabIndex={-1}>
                        <h3 className="line-clamp-2 text-[15px] leading-snug font-semibold text-foreground">{video.name}</h3>
                    </Link>
                    <div className="mt-1 flex flex-col text-[13px] leading-5 text-muted-foreground">
                        {showChannel && video.channel && (
                            <Link href={`/channels/${video.channel.id}`} className="truncate hover:text-foreground">
                                {video.channel.name}
                            </Link>
                        )}
                        <Meta video={video} />
                    </div>
                </div>
                <VideoActions video={video} onChange={setOverride} />
            </div>
        </article>
    );
}

/** Строка для колонки «Далее» и компактных списков. */
export function VideoRow({ video, active, className }: { video: Video; active?: boolean; className?: string }) {
    return (
        <Link
            href={`/watch/${video.id}`}
            className={cn(
                'group flex gap-3 rounded-xl p-1.5 transition-colors hover:bg-accent/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                active && 'bg-accent',
                className,
            )}
        >
            <VideoThumbnail video={video} className="w-40 shrink-0 sm:w-44" rounded="rounded-lg" />
            <div className="min-w-0 flex-1 py-0.5">
                <h4 className="line-clamp-2 text-sm leading-snug font-semibold">{video.name}</h4>
                <div className="mt-1 flex flex-col text-xs leading-5 text-muted-foreground">
                    {video.channel && <span className="truncate">{video.channel.name}</span>}
                    <Meta video={video} />
                </div>
            </div>
        </Link>
    );
}

export function VideoCardSkeleton() {
    return (
        <div className="flex flex-col gap-3">
            <div className="aspect-video animate-pulse rounded-xl bg-muted" />
            <div className="flex gap-3">
                <div className="size-9 animate-pulse rounded-full bg-muted" />
                <div className="flex-1 space-y-2 pt-1">
                    <div className="h-3.5 w-11/12 animate-pulse rounded bg-muted" />
                    <div className="h-3 w-2/3 animate-pulse rounded bg-muted" />
                </div>
            </div>
        </div>
    );
}
