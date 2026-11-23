import { Clapperboard, HardDrive, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Player } from '@/components/player';
import { Button } from '@/components/ui/button';
import { formatBytes, formatDuration } from '@/lib/format';
import { cancelOfflineDownload, offlineDownloadPercent, type OfflineDownload, useOfflineDownloads } from '@/lib/offline-downloads';
import { deleteAllSavedVideos, deleteSavedVideo, offlinePlayback, onSavedVideosChange, type SavedVideo, savedVideos, supportsOfflineVideo } from '@/lib/offline-video';
import { cn } from '@/lib/utils';

function Preview({ poster }: { poster: Blob | null }) {
    const [url, setUrl] = useState<string | null>(null);

    useEffect(() => {
        if (!poster) {
            setUrl(null);
            return;
        }
        const next = URL.createObjectURL(poster);
        setUrl(next);
        return () => URL.revokeObjectURL(next);
    }, [poster]);

    return url ? <img src={url} alt="" className="aspect-video w-24 shrink-0 rounded-md object-cover" /> : (
        <span className="grid aspect-video w-24 shrink-0 place-items-center rounded-md bg-muted text-muted-foreground">
            <Clapperboard className="size-5" aria-hidden="true" />
        </span>
    );
}

function DownloadRow({ download }: { download: OfflineDownload }) {
    const percent = offlineDownloadPercent(download);
    const received = formatBytes(download.received);
    const detail = percent === undefined
        ? download.received ? `${received} скачано` : 'Подготовка к скачиванию…'
        : `${percent}% · ${received} из ${formatBytes(download.total)}`;

    return (
        <li className="flex items-center gap-3 rounded-xl border border-border/60 bg-card p-3">
            <div className="min-w-0 flex-1 space-y-1.5">
                <p className="line-clamp-2 text-sm font-medium">{download.title}</p>
                <p className="text-xs text-muted-foreground tabular-nums">{download.cancelling ? 'Отменяем…' : detail}</p>
                <progress
                    max={100}
                    value={percent}
                    aria-label={`Прогресс скачивания «${download.title}»`}
                    className="block h-2 w-full accent-brand"
                />
            </div>
            <Button
                size="icon"
                variant="ghost"
                className="size-11 rounded-full"
                disabled={download.cancelling}
                aria-label={`Отменить скачивание «${download.title}»`}
                onClick={() => cancelOfflineDownload(download.id)}
            >
                <X />
            </Button>
        </li>
    );
}

export function DeviceLibrary({ disconnected = false }: { disconnected?: boolean }) {
    const supported = supportsOfflineVideo();
    const downloads = useOfflineDownloads();
    const [entries, setEntries] = useState<SavedVideo[] | null>(null);
    const [selectedId, setSelectedId] = useState(() => {
        const url = new URL(location.href);
        return Number(url.searchParams.get('video') ?? url.pathname.match(/^\/watch\/(\d+)$/)?.[1]) || null;
    });
    const [playing, setPlaying] = useState<{ entry: SavedVideo; source: Awaited<ReturnType<typeof offlinePlayback>> } | null>(null);
    const [editing, setEditing] = useState(false);
    const [removing, setRemoving] = useState<SavedVideo | 'all' | null>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const swipedRow = useRef<HTMLDivElement | null>(null);
    const selected = entries?.find((entry) => entry.id === selectedId);
    const source = selected && playing?.entry === selected ? playing.source : null;

    useEffect(() => {
        if (!supported) return;
        let active = true;
        let read = 0;
        const refresh = () => {
            const version = ++read;
            void savedVideos().then((list) => {
                if (!active || version !== read) return;

                setEntries((current) => {
                    const previous = new Map(current?.map((entry) => [entry.fileName, entry]));
                    return list.map((entry) => previous.get(entry.fileName) ?? entry);
                });
            }).catch(() => {
                if (active && version === read) setError('Не удалось открыть локальную библиотеку.');
            });
        };
        const unsubscribe = onSavedVideosChange(refresh);
        refresh();
        return () => {
            active = false;
            unsubscribe();
        };
    }, [supported]);

    useEffect(() => {
        if (!selected) return;
        let active = true;
        let release: (() => void) | undefined;
        void offlinePlayback(selected).then((local) => {
            if (!active) {
                local.release();
                return;
            }
            release = local.release;
            setPlaying({ entry: selected, source: local });
        }).catch(() => {
            if (active) setError('Сохранённый файл недоступен. Загрузите видео снова, когда появится связь.');
        });
        return () => {
            active = false;
            release?.();
        };
    }, [selected]);

    function closeSwipe() {
        swipedRow.current?.scrollTo({ left: 0, behavior: 'instant' });
        swipedRow.current = null;
    }

    function activateRow(row: HTMLDivElement) {
        if (swipedRow.current === row) return;
        closeSwipe();
        swipedRow.current = row;
    }

    async function remove() {
        if (!removing) return;
        const all = removing === 'all';
        setProcessing(true);
        setError(null);
        closeSwipe();
        if (all || removing.id === selectedId) setSelectedId(null);
        try {
            if (all) await deleteAllSavedVideos();
            else await deleteSavedVideo(removing.id);
            if (all || entries?.length === 1) setEditing(false);
        } catch {
            setError(all ? 'Не удалось удалить сохранённые видео.' : 'Не удалось удалить локальную копию.');
        } finally {
            setRemoving(null);
            setProcessing(false);
        }
    }

    return (
        <section className="space-y-4" data-no-pull-refresh>
            <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                <div>
                    <h1 className="font-display text-2xl font-bold">На устройстве</h1>
                    {disconnected && (
                        <p role="status" className="mt-1 text-sm text-muted-foreground">Нет связи с сервером MyTube.</p>
                    )}
                    {!!entries?.length && (
                        <p className="mt-1 text-sm text-muted-foreground">Занято {formatBytes(entries.reduce((sum, item) => sum + item.bytes, 0))}</p>
                    )}
                </div>
                {!!entries?.length && (
                    <div className="flex items-center gap-1">
                        {editing && (
                            <Button type="button" variant="ghost" className="min-h-11 rounded-full text-destructive" onClick={() => setRemoving('all')}>
                                Удалить все
                            </Button>
                        )}
                        <Button type="button" variant="ghost" className="min-h-11 rounded-full" onClick={() => { closeSwipe(); setEditing(!editing); }}>
                            {editing ? 'Готово' : 'Изменить'}
                        </Button>
                    </div>
                )}
            </div>
            {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
            {source && (
                <section className="space-y-2" aria-label="Воспроизведение">
                    <Player key={source.video.id} video={source.video} poster={source.poster} autoplayNext={false} />
                    <h2 className="font-display text-lg font-semibold">{source.video.name}</h2>
                </section>
            )}
            {downloads.size > 0 && (
                <section className="space-y-2" aria-label="Активные скачивания">
                    <h2 className="font-display text-lg font-semibold">Сохранение для офлайна</h2>
                    <ul className="space-y-2">
                        {Array.from(downloads.values(), (download) => <DownloadRow key={download.id} download={download} />)}
                    </ul>
                </section>
            )}
            {!supported ? (
                <p className="text-sm text-muted-foreground">Для офлайн-видео нужен современный браузер и защищённый адрес MyTube.</p>
            ) : entries === null && !error ? (
                <p role="status" className="text-sm text-muted-foreground">Открываем библиотеку…</p>
            ) : entries?.length === 0 && downloads.size === 0 ? (
                <div className="grid justify-items-center gap-3 rounded-2xl border border-dashed border-border px-5 py-10 text-center">
                    <HardDrive className="size-6 text-muted-foreground" aria-hidden="true" />
                    <p className="font-medium">Сохранённых видео пока нет</p>
                    <p className="text-sm text-muted-foreground">В меню ⋮ рядом с видео выберите «Сохранить для офлайна».</p>
                </div>
            ) : (
                <ul className="divide-y divide-border/60">
                    {entries?.map((entry) => (
                        <li key={entry.id} className="py-2">
                            <div
                                className={cn('flex items-center gap-2', !editing && 'scrollbar-none snap-x snap-mandatory overflow-x-auto overscroll-x-contain')}
                                onPointerDown={(event) => activateRow(event.currentTarget)}
                                onFocus={(event) => activateRow(event.currentTarget)}
                            >
                                <button
                                    type="button"
                                    aria-pressed={selectedId === entry.id}
                                    className={cn(
                                        'pressable flex min-w-0 items-center gap-3 rounded-lg p-1 text-left transition-[background-color,opacity,scale] hover:bg-accent active:bg-accent aria-pressed:bg-accent',
                                        editing ? 'flex-1' : 'w-full flex-none snap-start',
                                    )}
                                    onClick={() => { closeSwipe(); setError(null); setSelectedId(entry.id); }}
                                >
                                    <Preview poster={entry.poster} />
                                    <span className="min-w-0">
                                        <span className="line-clamp-2 block text-sm font-medium">{entry.video.name}</span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {formatDuration(entry.video.duration_seconds)} · {formatBytes(entry.bytes)}
                                        </span>
                                    </span>
                                </button>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    className="h-auto min-h-11 min-w-24 flex-none snap-end self-stretch rounded-lg"
                                    aria-label={`Удалить с устройства «${entry.video.name}»`}
                                    onClick={() => setRemoving(entry)}
                                >
                                    Удалить
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <ConfirmDialog open={removing !== null} onOpenChange={(open) => !open && setRemoving(null)}
                title={removing === 'all' ? 'Удалить все видео с устройства?' : 'Удалить видео с устройства?'}
                confirmLabel={removing === 'all' ? 'Удалить все' : 'Удалить'} processing={processing} onConfirm={remove}>
                <p>{removing === 'all'
                    ? 'Будут удалены все сохранённые видео на этом устройстве.'
                    : `Локальная копия «${removing?.video.name ?? ''}» будет удалена.`}</p>
            </ConfirmDialog>
        </section>
    );
}
