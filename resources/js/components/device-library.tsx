import { Clapperboard, HardDrive, Minus, X } from 'lucide-react';
import { type CSSProperties, type PointerEvent, useEffect, useRef, useState } from 'react';
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

    return url ? <img src={url} alt="" draggable={false} className="aspect-video w-20 shrink-0 rounded-md object-cover sm:w-24" /> : (
        <span className="grid aspect-video w-20 shrink-0 place-items-center rounded-md bg-muted text-muted-foreground sm:w-24">
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

const SWIPE_ACTION_WIDTH = 96;

function SavedVideoRow({ entry, editing, selected, open, onOpenChange, onPlay, onRemove }: {
    entry: SavedVideo;
    editing: boolean;
    selected: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onPlay: () => void;
    onRemove: () => void;
}) {
    const swipeSurface = useRef<HTMLDivElement>(null);
    const gesture = useRef<{ pointerId: number; x: number; y: number; offset: number; width: number; dragging: boolean } | null>(null);
    const suppressClick = useRef(false);
    const offset = open ? SWIPE_ACTION_WIDTH : 0;
    const deleteLabel = `Удалить с устройства «${entry.video.name}»`;

    function startSwipe(event: PointerEvent<HTMLButtonElement>) {
        if (!event.isPrimary || event.button !== 0) return;
        suppressClick.current = false;
        if (editing) return;
        if (!open) onOpenChange(false);
        gesture.current = {
            pointerId: event.pointerId, x: event.clientX, y: event.clientY, offset, width: 0, dragging: false,
        };
    }

    function moveSwipe(event: PointerEvent<HTMLButtonElement>) {
        const start = gesture.current;
        if (!start || start.pointerId !== event.pointerId) return;
        const dx = event.clientX - start.x;
        const dy = event.clientY - start.y;
        if (!start.dragging) {
            if (Math.max(Math.abs(dx), Math.abs(dy)) < 8) return;
            suppressClick.current = true;
            if (Math.abs(dx) <= Math.abs(dy) || (!open && dx > 0)) {
                gesture.current = null;
                return;
            }
            start.dragging = true;
            start.width = event.currentTarget.offsetWidth;
            event.currentTarget.setPointerCapture(event.pointerId);
            swipeSurface.current?.setAttribute('data-swiping', '');
        }

        const distance = Math.max(0, Math.min(start.width, start.offset - dx));
        swipeSurface.current?.style.setProperty('--swipe-drag-offset', `${distance}px`);
    }

    function endSwipe(event: PointerEvent<HTMLButtonElement>) {
        const start = gesture.current;
        if (!start || start.pointerId !== event.pointerId) return;
        gesture.current = null;
        if (!start.dragging) return;

        swipeSurface.current?.removeAttribute('data-swiping');
        swipeSurface.current?.style.removeProperty('--swipe-drag-offset');
        if (event.type !== 'pointerup') return;

        const distance = start.offset + start.x - event.clientX;
        if (distance >= Math.max(SWIPE_ACTION_WIDTH, start.width * 0.55)) {
            onRemove();
            return;
        }
        onOpenChange(distance >= SWIPE_ACTION_WIDTH / 2);
    }

    return (
        <li className="py-1.5">
            <div
                ref={swipeSurface}
                className={cn('group/swipe relative flex items-center overflow-hidden rounded-lg [--swipe-offset:var(--swipe-drag-offset,var(--swipe-rest-offset))]', editing ? 'gap-1' : 'touch-pan-y')}
                style={{ '--swipe-rest-offset': `${offset}px` } as CSSProperties}
            >
                {editing ? (
                    <Button key="edit-remove" type="button" variant="ghost" size="icon" className="size-11 rounded-full" aria-label={deleteLabel} onClick={onRemove}>
                        <span className="grid size-5 place-items-center rounded-full bg-destructive text-destructive-foreground" aria-hidden="true">
                            <Minus className="size-3.5 stroke-[2.5]" />
                        </span>
                    </Button>
                ) : (
                    <Button
                        type="button"
                        variant="destructive"
                        className={cn(
                            'absolute inset-0 h-auto min-h-11 w-full justify-start rounded-none p-0 transition-none motion-safe:transition-transform motion-safe:duration-200 group-data-swiping/swipe:transition-none',
                            !open && 'pointer-events-none',
                        )}
                        style={{ translate: 'calc(100% - var(--swipe-offset))' }}
                        aria-label={deleteLabel}
                        aria-hidden={!open || undefined}
                        tabIndex={open ? 0 : -1}
                        onClick={onRemove}
                    >
                        <span
                            className="text-center motion-safe:transition-transform motion-safe:duration-200 group-data-swiping/swipe:transition-none"
                            style={{ width: SWIPE_ACTION_WIDTH, translate: 'calc(var(--swipe-offset) / 2 - 50%)' }}
                        >
                            Удалить
                        </span>
                    </Button>
                )}
                <button
                    type="button"
                    aria-pressed={selected}
                    className={cn(
                        'relative flex min-w-0 items-center gap-3 rounded-lg bg-background p-1 text-left outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring active:bg-accent aria-pressed:bg-accent',
                        editing ? 'flex-1' : 'w-full flex-none touch-pan-y motion-safe:transition-[translate,background-color] motion-safe:duration-200 group-data-swiping/swipe:transition-none',
                    )}
                    style={editing ? undefined : { translate: 'calc(-1 * var(--swipe-offset))' }}
                    onPointerDown={startSwipe}
                    onPointerMove={moveSwipe}
                    onPointerUp={endSwipe}
                    onPointerCancel={endSwipe}
                    onLostPointerCapture={(event) => {
                        if (event.target === event.currentTarget) endSwipe(event);
                    }}
                    onClick={(event) => {
                        if (suppressClick.current && event.detail > 0) return;
                        onPlay();
                    }}
                >
                    <Preview poster={entry.poster} />
                    <span className="min-w-0 flex-1">
                        <span className="line-clamp-2 text-sm leading-snug font-medium [overflow-wrap:anywhere]">{entry.video.name}</span>
                        <span className="block truncate text-xs text-muted-foreground">
                            {formatDuration(entry.video.duration_seconds)} · {formatBytes(entry.bytes)}
                        </span>
                    </span>
                </button>
            </div>
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
    const [swipedId, setSwipedId] = useState<number | null>(null);
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

    async function remove() {
        if (!removing) return;
        const all = removing === 'all';
        setProcessing(true);
        setError(null);
        setSwipedId(null);
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
            <header>
                <div className="flex items-center justify-between gap-1">
                    <h1 className="font-display text-2xl font-bold">На устройстве</h1>
                    {!!entries?.length && (
                        <Button type="button" variant="ghost" className="min-h-11 rounded-full px-2.5" onClick={() => { setSwipedId(null); setEditing(!editing); }}>
                            {editing ? 'Готово' : 'Изменить'}
                        </Button>
                    )}
                </div>
                {disconnected && (
                    <p role="status" className="mt-1 text-sm text-muted-foreground">Нет связи с сервером MyTube.</p>
                )}
                {!!entries?.length && (
                    <div className="mt-1 flex flex-wrap items-center justify-between gap-x-1">
                        <p className="text-sm text-muted-foreground">Занято {formatBytes(entries.reduce((sum, item) => sum + item.bytes, 0))}</p>
                        {editing && (
                            <Button type="button" variant="ghost" className="min-h-11 rounded-full px-2.5 text-destructive" onClick={() => setRemoving('all')}>
                                Удалить все
                            </Button>
                        )}
                    </div>
                )}
            </header>
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
                        <SavedVideoRow
                            key={entry.id}
                            entry={entry}
                            editing={editing}
                            selected={selectedId === entry.id}
                            open={swipedId === entry.id}
                            onOpenChange={(open) => setSwipedId(open ? entry.id : null)}
                            onPlay={() => { setSwipedId(null); setError(null); setSelectedId(entry.id); }}
                            onRemove={() => { setSwipedId(null); setRemoving(entry); }}
                        />
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
