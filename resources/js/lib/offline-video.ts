import type { PlayableVideo, SubtitleTrack } from '@/types';

export const DEVICE_LIBRARY = '/on-device';

const DATABASE = 'mytube-device-library';
const VIDEOS = 'videos';
const libraryListeners = new Set<() => void>();

export interface SavedVideo {
    id: number;
    video: PlayableVideo;
    fileName: string;
    bytes: number;
    savedAt: number;
    poster: Blob | null;
    tracks: { track: SubtitleTrack; file: Blob }[];
}

export function supportsOfflineVideo() {
    return window.isSecureContext && 'serviceWorker' in navigator && 'indexedDB' in window &&
        typeof navigator.storage?.getDirectory === 'function' &&
        typeof window.FileSystemFileHandle?.prototype.createWritable === 'function';
}

export async function deviceLibraryReady() {
    const registration = await navigator.serviceWorker.getRegistration();
    return Boolean(registration?.active && await caches.match(DEVICE_LIBRARY));
}

async function directory() {
    return (await navigator.storage.getDirectory()).getDirectoryHandle(DATABASE, { create: true });
}

function openDatabase(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DATABASE, 1);
        request.onupgradeneeded = () => request.result.createObjectStore(VIDEOS, { keyPath: 'id' });
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function read<T>(query: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    const database = await openDatabase();
    try {
        return await new Promise<T>((resolve, reject) => {
            const request = query(database.transaction(VIDEOS).objectStore(VIDEOS));
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    } finally {
        database.close();
    }
}

/** Read and replace the index entry in one transaction, including across tabs. */
async function update(id: number, next?: SavedVideo): Promise<SavedVideo | undefined> {
    const database = await openDatabase();
    try {
        return await new Promise((resolve, reject) => {
            const transaction = database.transaction(VIDEOS, 'readwrite');
            const store = transaction.objectStore(VIDEOS);
            const previous = store.get(id);
            previous.onsuccess = () => next ? store.put(next) : store.delete(id);
            transaction.oncomplete = () => {
                resolve(previous.result as SavedVideo | undefined);
                libraryListeners.forEach((listener) => listener());
            };
            transaction.onabort = () => reject(transaction.error);
        });
    } finally {
        database.close();
    }
}

export function savedVideo(id: number) {
    return read<SavedVideo | undefined>((store) => store.get(id));
}

export async function savedVideos() {
    const videos = await read<SavedVideo[]>((store) => store.getAll());
    return videos.sort((a, b) => b.savedAt - a.savedAt);
}

export function onSavedVideosChange(listener: () => void) {
    libraryListeners.add(listener);
    return () => libraryListeners.delete(listener);
}

async function removeFile(folder: FileSystemDirectoryHandle, name: string) {
    try {
        await folder.removeEntry(name);
    } catch (error) {
        if (!(error instanceof DOMException) || error.name !== 'NotFoundError') {
            console.warn('MyTube: не удалось удалить локальный файл', error);
        }
    }
}

async function optionalFile(url: string, signal: AbortSignal): Promise<Blob | null> {
    try {
        const response = await fetch(url, { signal });
        return response.ok ? await response.blob() : null;
    } catch {
        return null;
    }
}

export async function saveVideoForOffline(
    video: PlayableVideo,
    signal: AbortSignal,
    onProgress: (received: number, total: number) => void,
) {
    if (!await deviceLibraryReady()) {
        throw new Error('Офлайн-раздел ещё готовится. Попробуйте через несколько секунд.');
    }

    const folder = await directory();
    const response = await fetch(video.stream_url, { signal, cache: 'no-store' });
    const body = response.body;
    const fileName = `${video.id}-${crypto.randomUUID()}.mp4`;
    let received = 0;
    let reportedAt = performance.now();
    let saved = false;
    try {
        if (response.status !== 200 || !body) throw new Error('Не удалось получить файл видео.');
        const contentLength = Number(response.headers.get('content-length'));
        const total = contentLength || video.file_size || 0;
        const { quota, usage } = await navigator.storage.estimate().catch(() => ({} as StorageEstimate));
        if (quota !== undefined && total > quota - (usage ?? 0)) {
            throw new DOMException('Недостаточно места', 'QuotaExceededError');
        }

        onProgress(0, total);
        const handle = await folder.getFileHandle(fileName, { create: true });
        const stream = await handle.createWritable();
        await body.pipeThrough(new TransformStream<Uint8Array, Uint8Array>({
            transform(chunk, controller) {
                received += chunk.byteLength;
                const now = performance.now();
                if (now - reportedAt >= 200 || received === total) {
                    onProgress(received, total);
                    reportedAt = now;
                }
                controller.enqueue(chunk);
            },
        })).pipeTo(stream, { signal });
        if (received === 0 || (contentLength > 0 && received !== contentLength)) {
            throw new Error('Файл скачался не полностью. Попробуйте ещё раз.');
        }

        const [poster, tracks] = await Promise.all([
            video.thumbnail ? optionalFile(video.thumbnail, signal) : null,
            Promise.all(video.subtitles.map(async (track) => {
                const file = await optionalFile(track.url, signal);
                return file ? { track, file } : null;
            })),
        ]);
        signal.throwIfAborted();
        const previouslySaved = await update(video.id, {
            id: video.id, video, fileName, bytes: received, savedAt: Date.now(), poster,
            tracks: tracks.filter((track) => track !== null),
        });
        saved = true;
        if (previouslySaved) await removeFile(folder, previouslySaved.fileName);
    } finally {
        if (!saved) {
            await body?.cancel().catch(() => {});
            await removeFile(folder, fileName);
        }
    }
}

export async function deleteSavedVideo(id: number) {
    const folder = await directory();
    const previous = await update(id);
    if (previous) await removeFile(folder, previous.fileName);
}

export async function offlinePlayback(entry: SavedVideo) {
    const handle = await (await directory()).getFileHandle(entry.fileName);
    const file = await handle.getFile();
    if (file.size !== entry.bytes) throw new Error('Сохранённый файл недоступен.');

    const urls: string[] = [];
    const localUrl = (blob: Blob) => {
        const url = URL.createObjectURL(blob);
        urls.push(url);
        return url;
    };
    const video: PlayableVideo = {
        ...entry.video,
        stream_url: localUrl(file),
        subtitles: entry.tracks.map(({ track, file }) => ({ ...track, url: localUrl(file) })),
    };

    return {
        video,
        poster: entry.poster ? localUrl(entry.poster) : null,
        release: () => urls.forEach((url) => URL.revokeObjectURL(url)),
    };
}
