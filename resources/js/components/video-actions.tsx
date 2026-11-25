import { router, usePage } from '@inertiajs/react';
import { CloudDownload, EllipsisVertical, ExternalLink, FileX, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { DeleteDialog } from '@/components/delete-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { VideoDownloadItems } from '@/components/video-download-items';
import { formatBytes } from '@/lib/format';
import { cn } from '@/lib/utils';
import { removeProgress } from '@/lib/watch-progress';
import type { DownloadState, Video, VideoDetail } from '@/types';

const LIST_PROPS = ['deletePin', 'downloads'];
const WATCH_PROPS = ['video', 'deletePin', 'downloads'];
const HOME_AFTER_DELETE = ['deletePin', 'latest', 'shelves'];

export function VideoActions({ video, className, onChange }: {
    video: Video | VideoDetail;
    className?: string;
    onChange?: (next: DownloadState | 'removed') => void;
}) {
    const { component } = usePage();
    const [dialog, setDialog] = useState<'file' | 'delete' | null>(null);
    const [sending, setSending] = useState(false);
    const detail = 'stream_url' in video ? video : null;
    const state = video.download_state;
    const streamUrl = detail ? detail.stream_url : state === 'downloaded' ? `/api/videos/${video.id}/stream` : null;
    const canCancel = state === 'queued' && !!video.download_requested_at && !video.auto_download;
    const downloadMode = video.download_on_demand === true ? 'on-demand' : video.download_on_demand === false ? 'auto' : 'channel';
    const size = detail?.file_size ? ` (${formatBytes(detail.file_size)})` : '';

    const request = (method: 'post' | 'delete', next: DownloadState) =>
        router.visit(`/videos/${video.id}/download`, {
            method,
            preserveScroll: true,
            preserveState: true,
            only: detail ? WATCH_PROPS : LIST_PROPS,
            onStart: () => setSending(true),
            onFinish: () => setSending(false),
            onSuccess: () => onChange?.(next),
        });

    const setDownloadMode = (mode: string) =>
        router.patch(
            `/videos/${video.id}`,
            { download_on_demand: mode === 'channel' ? null : mode === 'on-demand' },
            { preserveScroll: true, only: ['video', 'downloads'] },
        );

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant={detail ? 'secondary' : 'ghost'}
                        size="icon"
                        aria-label="Действия с видео"
                        className={cn('size-11 shrink-0 rounded-full', !detail && 'text-muted-foreground md:size-8', className)}
                    >
                        <EllipsisVertical className="size-[18px]" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" collisionPadding={8} className="w-64 max-w-[calc(100vw-1rem)]">
                    <VideoDownloadItems key={video.id} video={video} streamUrl={streamUrl} />
                    {detail && !streamUrl && (
                        <DropdownMenuItem asChild className="min-h-11">
                            <a href={`https://www.youtube.com/watch?v=${detail.external_id}`} target="_blank" rel="noreferrer noopener">
                                <ExternalLink />
                                Открыть на YouTube
                            </a>
                        </DropdownMenuItem>
                    )}
                    {state === 'available' && (
                        <DropdownMenuItem disabled={sending} onSelect={() => request('post', 'queued')} className="min-h-11">
                            <CloudDownload />
                            Скачать на сервер
                        </DropdownMenuItem>
                    )}
                    {canCancel && (
                        <DropdownMenuItem disabled={sending} onSelect={() => request('delete', 'available')} className="min-h-11">
                            <X />
                            Отменить скачивание на сервер
                        </DropdownMenuItem>
                    )}
                    {streamUrl && (
                        <DropdownMenuItem onSelect={() => setDialog('file')} className="min-h-11">
                            <FileX />
                            <span>
                                Выгрузить с сервера
                                <span className="block text-xs text-muted-foreground">Видео останется в каталоге</span>
                            </span>
                        </DropdownMenuItem>
                    )}
                    {detail && state !== 'unavailable' && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel>Скачивание на сервер</DropdownMenuLabel>
                            <DropdownMenuRadioGroup value={downloadMode} onValueChange={setDownloadMode}>
                                <DropdownMenuRadioItem className="min-h-11" value="channel">По настройкам канала</DropdownMenuRadioItem>
                                <DropdownMenuRadioItem className="min-h-11" value="on-demand">Только по запросу</DropdownMenuRadioItem>
                                <DropdownMenuRadioItem className="min-h-11" value="auto">Автоматически</DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                        </>
                    )}
                    {(detail || state === 'available' || canCancel || streamUrl) && <DropdownMenuSeparator />}
                    <DropdownMenuItem variant="destructive" onSelect={() => setDialog('delete')} className="min-h-11">
                        <Trash2 />
                        Удалить видео
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <DeleteDialog
                open={dialog === 'file'}
                onOpenChange={(open) => setDialog(open ? 'file' : null)}
                url={`/videos/${video.id}/file`}
                title="Выгрузить видео?"
                confirmLabel="Выгрузить"
                only={detail ? undefined : LIST_PROPS}
                onDeleted={() => {
                    setDialog(null);
                    onChange?.('available');
                }}
            >
                <p>Файл «{video.name}»{size} будет удалён с сервера. Видео останется в каталоге с обложкой.</p>
                <p>Для этого видео включится скачивание по запросу.</p>
            </DeleteDialog>

            <DeleteDialog
                open={dialog === 'delete'}
                onOpenChange={(open) => setDialog(open ? 'delete' : null)}
                url={`/videos/${video.id}`}
                title="Удалить видео?"
                only={detail ? undefined : component === 'home' ? HOME_AFTER_DELETE : LIST_PROPS}
                onDeleted={() => {
                    setDialog(null);
                    removeProgress(video.id);
                    if (component !== 'home') onChange?.('removed');
                }}
            >
                <p>
                    «{video.name}» пропадёт из каталога{streamUrl ? `, файл${size} будет удалён с сервера` : ''}.
                </p>
                <p>Скачиваться снова это видео не будет, даже когда канал обновится.</p>
            </DeleteDialog>
        </>
    );
}
