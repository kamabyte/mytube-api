import '../css/app.css';

import { Clapperboard, HardDrive, House, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Logo } from '@/components/logo';
import { Player } from '@/components/player';
import { Button } from '@/components/ui/button';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { formatBytes, formatDuration } from '@/lib/format';
import { DEVICE_LIBRARY, deleteSavedVideo, offlinePlayback, type SavedVideo, savedVideos, supportsOfflineVideo } from '@/lib/offline-video';
import { registerServiceWorker } from '@/lib/pwa';

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

function DeviceLibrary() {
    const supported = supportsOfflineVideo();
    const [entries, setEntries] = useState<SavedVideo[] | null>(null);
    const [selectedId, setSelectedId] = useState(() => {
        const url = new URL(location.href);
        return Number(url.searchParams.get('video') ?? url.pathname.match(/^\/watch\/(\d+)$/)?.[1]) || null;
    });
    const [playing, setPlaying] = useState<{ entry: SavedVideo; source: Awaited<ReturnType<typeof offlinePlayback>> } | null>(null);
    const [removing, setRemoving] = useState<SavedVideo | null>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const selected = entries?.find((entry) => entry.id === selectedId);
    const source = selected && playing?.entry === selected ? playing.source : null;

    useEffect(() => {
        if (!supported) return;
        let active = true;
        void savedVideos().then((list) => active && setEntries(list)).catch(() => {
            if (active) setError('Не удалось открыть локальную библиотеку.');
        });
        return () => { active = false; };
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
        const id = removing.id;
        setProcessing(true);
        setError(null);
        if (id === selectedId) setSelectedId(null);
        try {
            await deleteSavedVideo(id);
            setEntries((current) => current?.filter((entry) => entry.id !== id) ?? []);
            setRemoving(null);
        } catch {
            setRemoving(null);
            setError('Не удалось удалить локальную копию.');
        } finally {
            setProcessing(false);
        }
    }

    return (
        <main className="mx-auto min-h-dvh max-w-4xl px-4 pt-[env(safe-area-inset-top)] pb-[max(2rem,env(safe-area-inset-bottom))] sm:px-6">
            <header className="flex min-h-16 items-center justify-between gap-4 border-b border-border/60">
                <a href="/" aria-label="MyTube — главная"><Logo /></a>
                <Button asChild variant="ghost" className="min-h-11 rounded-full">
                    <a href="/"><House /> Главная</a>
                </Button>
            </header>
            <section className="mt-6 space-y-4">
                <div>
                    <h1 className="font-display text-2xl font-bold">На устройстве</h1>
                    {location.pathname !== DEVICE_LIBRARY && (
                        <p role="status" className="mt-1 text-sm text-muted-foreground">Нет связи с сервером MyTube.</p>
                    )}
                    {!!entries?.length && (
                        <p className="mt-1 text-sm text-muted-foreground">Занято {formatBytes(entries.reduce((sum, item) => sum + item.bytes, 0))}</p>
                    )}
                </div>
                {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
                {source && (
                    <section className="space-y-2" aria-label="Воспроизведение">
                        <Player key={source.video.id} video={source.video} poster={source.poster} autoplayNext={false} />
                        <h2 className="font-display text-lg font-semibold">{source.video.name}</h2>
                    </section>
                )}
                {!supported ? (
                    <p className="text-sm text-muted-foreground">Для офлайн-видео нужен современный браузер и защищённый адрес MyTube.</p>
                ) : entries === null && !error ? (
                    <p role="status" className="text-sm text-muted-foreground">Открываем библиотеку…</p>
                ) : entries?.length === 0 ? (
                    <div className="grid justify-items-center gap-3 rounded-2xl border border-dashed border-border px-5 py-10 text-center">
                        <HardDrive className="size-6 text-muted-foreground" aria-hidden="true" />
                        <p className="font-medium">Сохранённых видео пока нет</p>
                        <p className="text-sm text-muted-foreground">Откройте видео при доступном сервере и нажмите «Скачать на устройство».</p>
                    </div>
                ) : (
                    <ul className="divide-y divide-border/60">
                        {entries?.map((entry) => (
                            <li key={entry.id} className="flex items-center gap-2 py-2">
                                <button
                                    type="button"
                                    aria-pressed={selectedId === entry.id}
                                    className="flex min-w-0 flex-1 items-center gap-3 rounded-lg p-1 text-left hover:bg-accent aria-pressed:bg-accent"
                                    onClick={() => { setError(null); setSelectedId(entry.id); }}
                                >
                                    <Preview poster={entry.poster} />
                                    <span className="min-w-0">
                                        <span className="line-clamp-2 block text-sm font-medium">{entry.video.name}</span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {formatDuration(entry.video.duration_seconds)} · {formatBytes(entry.bytes)}
                                        </span>
                                    </span>
                                </button>
                                <Button size="icon" variant="ghost" className="size-11 shrink-0" aria-label={`Удалить с устройства «${entry.video.name}»`} onClick={() => setRemoving(entry)}>
                                    <Trash2 />
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
            <ConfirmDialog open={removing !== null} onOpenChange={(open) => !open && setRemoving(null)}
                title="Удалить видео с устройства?" confirmLabel="Удалить" processing={processing} onConfirm={remove}>
                <p>Локальная копия «{removing?.video.name}» будет удалена.</p>
            </ConfirmDialog>
        </main>
    );
}

initializeTheme();
registerServiceWorker();
createRoot(document.getElementById('mytube-device')!).render(<TooltipProvider><DeviceLibrary /></TooltipProvider>);
