import { useSyncExternalStore } from 'react';
import { toast } from 'sonner';
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
let downloadToast: ReturnType<typeof toast> | undefined;

function showDownloadToast(message: string, description: string, failed = false) {
    if (downloadToast !== undefined) toast.dismiss(downloadToast);
    downloadToast = failed
        ? toast.error(message, { description, duration: 5000, className: 'pointer-events-none' })
        : toast(message, { description, duration: 3000 });
    return downloadToast;
}

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

    const toastId = showDownloadToast('Скачивание для офлайна началось', video.name);
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
                update(video.id, { ...download, received, total });
            });
        }
    } catch (error) {
        if (signal.aborted) {
            if (downloadToast === toastId) toast.dismiss(toastId);
        } else {
            const description = error instanceof DOMException && error.name === 'QuotaExceededError'
                ? 'На устройстве не хватает места.'
                : error instanceof Error ? error.message : undefined;
            showDownloadToast('Не удалось сохранить видео для офлайна', [video.name, description].filter(Boolean).join(' · '), true);
        }
    } finally {
        update(video.id);
    }
}
