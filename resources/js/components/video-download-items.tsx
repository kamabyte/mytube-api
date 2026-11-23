import { CircleCheck, Download, HardDriveDownload, X } from 'lucide-react';
import { useEffect, useState, useSyncExternalStore } from 'react';
import { toast } from 'sonner';
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { formatBytes } from '@/lib/format';
import { DEVICE_LIBRARY, saveVideoForOffline, savedVideo, supportsOfflineVideo } from '@/lib/offline-video';
import type { Video, VideoDetail } from '@/types';

// Загрузка переживает закрытие меню и переходы внутри приложения.
const activeDownloads = new Map<number, AbortController>();
const listeners = new Set<() => void>();

function subscribe(listener: () => void) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

async function saveOffline(video: Video | VideoDetail) {
    if (activeDownloads.has(video.id)) return;

    const controller = new AbortController();
    const { signal } = controller;
    activeDownloads.set(video.id, controller);
    listeners.forEach((listener) => listener());

    const message = 'Сохраняем для офлайна';
    const hint = 'Держите MyTube открытым';
    const toastId = toast.loading(message, { description: hint });
    void navigator.storage.persist?.().catch(() => {});

    try {
        if (!await savedVideo(video.id)) {
            let detail: VideoDetail;
            if ('stream_url' in video) {
                detail = video;
            } else {
                const response = await fetch(`/videos/${video.id}/offline`, {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                    signal,
                });
                if (!response.ok) throw new Error('Не удалось получить данные видео.');
                detail = await response.json() as VideoDetail;
            }

            if (!detail.stream_url) throw new Error('Видео ещё не скачано на сервер.');
            await saveVideoForOffline({ ...detail, stream_url: detail.stream_url }, signal, (received, total) => {
                const progress = total ? `${Math.min(99, Math.floor(received / total * 100))}%` : formatBytes(received);
                toast.loading(message, { id: toastId, description: `${progress} · ${hint}` });
            });
        }

        toast.success('Видео сохранено в разделе «Офлайн»', { id: toastId });
    } catch (error) {
        if (signal.aborted) {
            toast.dismiss(toastId);
        } else {
            const description = error instanceof DOMException && error.name === 'QuotaExceededError'
                ? 'На устройстве не хватает места.'
                : error instanceof Error ? error.message : undefined;
            toast.error('Не удалось сохранить видео для офлайна', { id: toastId, description });
        }
    } finally {
        activeDownloads.delete(video.id);
        listeners.forEach((listener) => listener());
    }
}

export function VideoDownloadItems({ video, streamUrl }: { video: Video | VideoDetail; streamUrl: string | null }) {
    const supported = supportsOfflineVideo();
    const saving = useSyncExternalStore(subscribe, () => activeDownloads.get(video.id), () => undefined);
    const [saved, setSaved] = useState(false);

    useEffect(() => {
        if (!supported || saving) return;
        let current = true;
        void savedVideo(video.id).then((entry) => {
            if (current) setSaved(Boolean(entry));
        }).catch(() => {});
        return () => { current = false; };
    }, [supported, saving, video.id]);

    if (!streamUrl && !saving && !saved) return null;

    return (
        <>
            {saving ? (
                <DropdownMenuItem onSelect={() => saving.abort()} className="min-h-11">
                    <X />
                    Отменить сохранение для офлайна
                </DropdownMenuItem>
            ) : saved ? (
                <DropdownMenuItem asChild className="min-h-11">
                    <a href={`${DEVICE_LIBRARY}?video=${video.id}`}>
                        <CircleCheck />
                        Смотреть офлайн
                    </a>
                </DropdownMenuItem>
            ) : supported && streamUrl && (
                <DropdownMenuItem onSelect={() => void saveOffline(video)} className="min-h-11">
                    <HardDriveDownload />
                    <span>
                        Сохранить для офлайна
                        <span className="block text-xs text-muted-foreground">В разделе «Офлайн»</span>
                    </span>
                </DropdownMenuItem>
            )}
            {streamUrl && (
                <DropdownMenuItem asChild className="min-h-11">
                    <a href={`${streamUrl}?download=1`} download>
                        <Download />
                        <span>
                            Скачать MP4
                            <span className="block text-xs text-muted-foreground">Отдельным файлом</span>
                        </span>
                    </a>
                </DropdownMenuItem>
            )}
            <DropdownMenuSeparator />
        </>
    );
}
