import { Head, Link } from '@inertiajs/react';
import { ChevronDown, Download, Ellipsis, ExternalLink, FileX, Trash2 } from 'lucide-react';
import { useCallback, useMemo, useRef, useState } from 'react';
import { ChannelAvatar } from '@/components/channel-avatar';
import { DeleteDialog } from '@/components/delete-dialog';
import { DownloadStage } from '@/components/download-stage';
import { Player, type PlayerHandle } from '@/components/player';
import { RichText } from '@/components/rich-text';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { VideoRow } from '@/components/video-card';
import { useStoredToggle } from '@/hooks/use-stored-toggle';
import { channelVideoCounts, formatBytes, formatDate, formatRelative, plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import { removeProgress } from '@/lib/watch-progress';
import type { ChannelSummary, Video, VideoDetail } from '@/types';

interface Props {
    video: VideoDetail;
    channel: ChannelSummary;
    upNext: Video[];
}

function Switch({ checked, onChange, label }: { checked: boolean; onChange: (value: boolean) => void; label: string }) {
    return (
        <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-muted-foreground select-none">
            {label}
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={cn(
                    'relative h-[22px] w-[38px] rounded-full transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    checked ? 'bg-brand' : 'bg-black/15 dark:bg-white/20',
                )}
            >
                <span
                    className={cn(
                        'absolute top-[2px] left-[2px] size-[18px] rounded-full bg-white shadow transition-transform',
                        checked && 'translate-x-4',
                    )}
                />
            </button>
        </label>
    );
}

function VideoMenu({ video }: { video: VideoDetail }) {
    const [confirming, setConfirming] = useState(false);
    const [unloading, setUnloading] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="secondary" size="icon" className="rounded-full" aria-label="Ещё">
                        <Ellipsis />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    <DropdownMenuItem asChild>
                        <a href={`https://www.youtube.com/watch?v=${video.external_id}`} target="_blank" rel="noreferrer noopener">
                            <ExternalLink />
                            Открыть на YouTube
                        </a>
                    </DropdownMenuItem>
                    {video.stream_url && (
                        <DropdownMenuItem disabled={video.auto_download} onSelect={() => setUnloading(true)} className="items-start">
                            <FileX className="mt-0.5" />
                            <span>
                                Удалить файл
                                <span className="block text-xs text-muted-foreground">
                                    {video.auto_download ? 'Канал скачивается целиком — файл скачался бы снова' : 'Видео останется в каталоге'}
                                </span>
                            </span>
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive" onSelect={() => setConfirming(true)}>
                        <Trash2 />
                        Удалить видео
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <DeleteDialog
                open={unloading}
                onOpenChange={setUnloading}
                url={`/videos/${video.id}/file`}
                title="Удалить файл видео?"
                confirmLabel="Удалить файл"
                onDeleted={() => setUnloading(false)}
            >
                <p>
                    «{video.name}» останется в каталоге с обложкой, а файл
                    {video.file_size ? ` (${formatBytes(video.file_size)})` : ''} будет удалён с диска.
                </p>
                <p>Чтобы посмотреть его, видео нужно будет скачать снова.</p>
            </DeleteDialog>

            <DeleteDialog
                open={confirming}
                onOpenChange={setConfirming}
                url={`/videos/${video.id}`}
                title="Удалить видео?"
                onDeleted={() => removeProgress(video.id)}
            >
                <p>
                    «{video.name}» пропадёт из каталога
                    {video.file_size ? `, файл (${formatBytes(video.file_size)}) будет удалён с диска` : ''}.
                </p>
                <p>Скачиваться снова это видео не будет, даже когда канал обновится.</p>
            </DeleteDialog>
        </>
    );
}

export default function Watch({ video, channel, upNext }: Props) {
    const [autoplay, setAutoplay] = useStoredToggle('mytube:autoplay', true);
    const [theater, setTheater] = useStoredToggle('mytube:theater', false);
    const [expanded, setExpanded] = useState(false);
    const player = useRef<PlayerHandle | null>(null);
    const onReady = useCallback((handle: PlayerHandle) => {
        player.current = handle;
    }, []);

    // Стабильная ссылка: плеер по ней понимает, что сменилось видео.
    // Без потока (видео из каталога) играть нечего — вместо плеера статус загрузки.
    const playable = useMemo(() => (video.stream_url ? { ...video, stream_url: video.stream_url, channel } : null), [video, channel]);

    const description = video.description?.trim() ?? '';
    const meta = [
        video.view_count > 0 ? plural(video.view_count, ['просмотр', 'просмотра', 'просмотров']) : null,
        video.published_at ? formatDate(video.published_at) : null,
    ].filter(Boolean);

    return (
        <>
            <Head title={video.name} />

            {/*
              Сетка, а не вложенные колонки: в режиме кинотеатра плеер растягивается
              на обе колонки, но остаётся тем же элементом дерева — иначе React
              пересоздал бы <video> и воспроизведение началось бы заново.
            */}
            <div
                className={cn(
                    '-mx-4 -mt-4 grid grid-cols-1 items-start gap-y-4 sm:mx-0 sm:mt-0 xl:grid-rows-[auto_1fr] xl:gap-x-8',
                    upNext.length > 0 && 'xl:grid-cols-[minmax(0,1fr)_400px]',
                )}
            >
                <div className={cn('min-w-0', theater && 'lg:-mx-8 lg:-mt-6 xl:col-span-full')}>
                    {playable ? (
                        <Player
                            video={playable}
                            next={upNext[0]}
                            autoplayNext={autoplay}
                            onAutoplayNextChange={setAutoplay}
                            theater={theater}
                            onTheaterChange={setTheater}
                            onReady={onReady}
                        />
                    ) : (
                        <DownloadStage video={video} />
                    )}
                </div>

                <div className="min-w-0 xl:col-start-1">
                    <div className="px-4 sm:px-0">
                        <h1 className="font-display text-xl leading-snug font-bold text-balance md:text-2xl">{video.name}</h1>

                        <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-3">
                            <Link href={`/channels/${channel.id}`} className="group flex min-w-0 items-center gap-3">
                                <ChannelAvatar channel={channel} className="size-10" />
                                <div className="min-w-0">
                                    <div className="truncate font-semibold group-hover:underline">{channel.name}</div>
                                    <div className="text-xs text-muted-foreground">
                                        {channelVideoCounts(channel)}
                                    </div>
                                </div>
                            </Link>

                            <div className="ml-auto flex gap-2">
                                {video.stream_url && (
                                    <Button asChild variant="secondary" className="rounded-full">
                                        <a href={video.stream_url} download={`${video.name}.mp4`}>
                                            <Download />
                                            Скачать
                                            {video.file_size ? <span className="text-muted-foreground">{formatBytes(video.file_size)}</span> : null}
                                        </a>
                                    </Button>
                                )}
                                <VideoMenu video={video} />
                            </div>
                        </div>

                        <div className="mt-4 rounded-2xl bg-black/[0.04] p-4 text-sm dark:bg-white/[0.06]">
                            <div className="font-semibold">
                                {meta.join(' · ')}
                                {video.downloaded_at && (
                                    <span className="font-normal text-muted-foreground"> · скачано {formatRelative(video.downloaded_at)}</span>
                                )}
                            </div>
                            {description ? (
                                <>
                                    <div className={cn('mt-2 leading-relaxed break-words whitespace-pre-line', !expanded && 'line-clamp-3')}>
                                        <RichText text={description} onSeek={(seconds) => player.current?.seek(seconds)} />
                                    </div>
                                    {(description.length > 200 || description.split('\n').length > 3) && (
                                        <button
                                            onClick={() => setExpanded((value) => !value)}
                                            className="mt-2 inline-flex items-center gap-1 font-semibold hover:opacity-70"
                                        >
                                            {expanded ? 'Свернуть' : 'Ещё'}
                                            <ChevronDown className={cn('size-4 transition-transform', expanded && 'rotate-180')} />
                                        </button>
                                    )}
                                </>
                            ) : (
                                <p className="mt-2 text-muted-foreground">Без описания.</p>
                            )}
                        </div>
                    </div>
                </div>

                {upNext.length > 0 && (
                    <aside
                        className={cn(
                            'mt-2 px-4 sm:px-0 xl:col-start-2 xl:mt-0',
                            theater ? 'xl:row-start-2' : 'xl:row-span-2 xl:row-start-1',
                        )}
                    >
                        <div className="mb-3 flex items-center justify-between gap-3">
                            <h2 className="font-display text-lg font-semibold">Далее</h2>
                            <Switch checked={autoplay} onChange={setAutoplay} label="Автовоспроизведение" />
                        </div>
                        <div className="-mx-1.5 flex flex-col gap-1">
                            {upNext.map((item) => (
                                <VideoRow key={item.id} video={item} />
                            ))}
                        </div>
                    </aside>
                )}
            </div>
        </>
    );
}
