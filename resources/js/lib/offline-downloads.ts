import { useSyncExternalStore } from 'react';
import { toast } from 'sonner';
import { formatBytes } from '@/lib/format';
import { saveVideoForOffline, savedVideo } from '@/lib/offline-video';
import type { Video, VideoDetail } from '@/types';

export interface OfflineDownload {
    id: number;
    title: string;
    received: number;
    total: number;
    cancelling: boolean;
    controller: AbortController;
}

const EMPTY: ReadonlyMap<number, OfflineDownload> = new Map();
let downloads = EMPTY;
const listeners = new Set<() => void>();

function subscribe(listener: () => void) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

function update(id: number, download?: OfflineDownload) {
    const next = new Map(downloads);
    if (download) next.set(id, download);
    else next.delete(id);
    downloads = next;
    listeners.forEach((listener) => listener());
}

export function useOfflineDownloads() {
    return useSyncExternalStore(subscribe, () => downloads, () => EMPTY);
}

export function useOfflineDownload(id: number) {
    return useSyncExternalStore(subscribe, () => downloads.get(id), () => undefined);
}

export function offlineDownloadPercent({ received, total }: OfflineDownload) {
    return total > 0 ? Math.min(99, Math.floor(received / total * 100)) : undefined;
}

export function cancelOfflineDownload(id: number) {
    const download = downloads.get(id);
    if (!download || download.cancelling) return;
    update(id, { ...download, cancelling: true });
    download.controller.abort();
}

export async function startOfflineDownload(video: Video | VideoDetail) {
    if (downloads.has(video.id)) return;

    const controller = new AbortController();
    const { signal } = controller;
    const download: OfflineDownload = {
        id: video.id,
        title: video.name,
        received: 0,
        total: 'file_size' in video ? video.file_size ?? 0 : 0,
        cancelling: false,
        controller,
    };
    update(video.id, download);

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
                if (signal.aborted) return;
                const current = { ...download, received, total };
                update(video.id, current);
                const percent = offlineDownloadPercent(current);
                const progress = percent === undefined ? formatBytes(received) : `${percent}%`;
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
        update(video.id);
    }
}
