import { CircleCheck, HardDriveDownload, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { formatBytes } from '@/lib/format';
import { DEVICE_LIBRARY, deviceLibraryReady, saveVideoForOffline, savedVideo, supportsOfflineVideo } from '@/lib/offline-video';
import type { PlayableVideo } from '@/types';

export function OfflineSaveButton({ video }: { video: PlayableVideo }) {
    const request = useRef<AbortController | null>(null);
    const supported = supportsOfflineVideo();
    const [ready, setReady] = useState(false);
    const [saved, setSaved] = useState(false);
    const [progress, setProgress] = useState<{ received: number; total: number } | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!supported) return;
        let current = true;
        const check = () => {
            void deviceLibraryReady().then((available) => current && setReady(available)).catch(() => {});
        };
        check();
        navigator.serviceWorker.addEventListener('controllerchange', check);
        void navigator.serviceWorker.ready.then(check);
        void savedVideo(video.id).then((entry) => current && setSaved(Boolean(entry))).catch(() => {});

        return () => {
            current = false;
            navigator.serviceWorker.removeEventListener('controllerchange', check);
            request.current?.abort();
        };
    }, [supported, video.id]);

    if (!supported) return null;

    async function start() {
        if (request.current) return;
        const controller = new AbortController();
        request.current = controller;
        setError(null);
        setProgress({ received: 0, total: video.file_size ?? 0 });
        void navigator.storage.persist?.().catch(() => {});

        try {
            await saveVideoForOffline(video, controller.signal, (received, total) => setProgress({ received, total }));
            setSaved(true);
        } catch (failure) {
            if (!controller.signal.aborted) {
                setError(failure instanceof DOMException && failure.name === 'QuotaExceededError'
                    ? 'На устройстве не хватает места.'
                    : failure instanceof Error ? failure.message : 'Не удалось скачать видео.');
            }
        } finally {
            request.current = null;
            setProgress(null);
        }
    }

    const percent = progress?.total ? Math.min(99, Math.floor(progress.received / progress.total * 100)) : null;

    return (
        <div className="mt-4 space-y-2" data-no-pull-refresh>
            {saved ? (
                <Button asChild variant="secondary" className="min-h-11 rounded-full">
                    <a href={`${DEVICE_LIBRARY}?video=${video.id}`}><CircleCheck /> На устройстве</a>
                </Button>
            ) : progress ? (
                <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <div role="status" className="text-sm text-muted-foreground">
                        {percent === null ? formatBytes(progress.received) : `${percent}%`} · держите MyTube открытым
                    </div>
                    <Button variant="ghost" onClick={() => request.current?.abort()} className="min-h-11 rounded-full">
                        <X /> Отменить
                    </Button>
                </div>
            ) : (
                <Button variant="secondary" className="min-h-11 rounded-full" onClick={start} disabled={!ready}>
                    <HardDriveDownload /> {ready ? 'Скачать на устройство' : 'Готовим офлайн…'}
                </Button>
            )}
            {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        </div>
    );
}
