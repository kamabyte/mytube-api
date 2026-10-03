import { useEffect, useSyncExternalStore } from 'react';
import type { Video } from '@/types';

/**
 * Позиция просмотра — только в этом браузере (localStorage). Сервер про
 * пользователей ничего не знает, а «продолжить с того же места» нужно.
 */
const STORAGE_KEY = 'mytube:progress:v1';
const MAX_ENTRIES = 300;

export interface ProgressEntry {
    position: number;
    duration: number;
    updatedAt: number;
    video: Video;
}

type Store = Record<string, ProgressEntry>;

const listeners = new Set<() => void>();
let cache: Store | null = null;

function read(): Store {
    if (cache) {
        return cache;
    }

    try {
        cache = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}') as Store;
    } catch {
        cache = {};
    }

    return cache;
}

function write(store: Store) {
    cache = store;

    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(store));
    } catch {
        // Приватный режим или переполнение — прогресс просто не переживёт перезагрузку.
    }

    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void) {
    listeners.add(listener);

    const onStorage = (event: StorageEvent) => {
        if (event.key === STORAGE_KEY) {
            cache = null;
            listener();
        }
    };
    window.addEventListener('storage', onStorage);

    return () => {
        listeners.delete(listener);
        window.removeEventListener('storage', onStorage);
    };
}

const EMPTY: Store = {};

export function useProgressStore(): Store {
    return useSyncExternalStore(subscribe, read, () => EMPTY);
}

export function getProgress(videoId: number): ProgressEntry | undefined {
    return read()[videoId];
}

/** Только то, что нужно карточке, — без описания и ссылки на поток. */
function snapshot(video: Video): Video {
    const { channel } = video;

    return {
        id: video.id,
        channel_id: video.channel_id,
        name: video.name,
        thumbnail: video.thumbnail,
        duration_seconds: video.duration_seconds,
        view_count: video.view_count,
        published_at: video.published_at,
        downloaded_at: video.downloaded_at,
        channel: channel
            ? { id: channel.id, name: channel.name, thumbnail: channel.thumbnail, is_playlist: channel.is_playlist }
            : undefined,
    };
}

export function saveProgress(video: Video, position: number, duration: number) {
    if (!duration || !Number.isFinite(duration)) {
        return;
    }

    const next: Store = {
        ...read(),
        [video.id]: { position, duration, updatedAt: Date.now(), video: snapshot(video) },
    };

    const entries = Object.entries(next);
    entries.sort(([, a], [, b]) => b.updatedAt - a.updatedAt);
    write(Object.fromEntries(entries.slice(0, MAX_ENTRIES)));
}

export function removeProgress(videoId: number) {
    const next = { ...read() };
    delete next[videoId];
    write(next);
}

/** Доля просмотренного 0..1; досмотренное почти до конца считается целиком. */
export function progressRatio(entry: ProgressEntry | undefined): number {
    if (!entry || !entry.duration) {
        return 0;
    }

    const ratio = entry.position / entry.duration;

    return ratio > 0.95 ? 1 : ratio;
}

/** С какого места продолжать: с начала, если почти досмотрели. */
export function resumePosition(entry: ProgressEntry | undefined): number {
    if (!entry) {
        return 0;
    }

    const ratio = progressRatio(entry);

    return ratio >= 1 || entry.position < 5 ? 0 : entry.position;
}

function isUnfinished(entry: ProgressEntry): boolean {
    const ratio = progressRatio(entry);

    return ratio > 0.02 && ratio < 1;
}

/**
 * Сверяет недосмотренные видео с сервером: удалённые (через веб в другом
 * браузере, командой, вместе с каналом) выкидывает, остальным обновляет
 * карточку — обложка и просмотры в снимке могли устареть.
 */
async function syncWithLibrary(signal: AbortSignal) {
    const ids = Object.values(read())
        .filter(isUnfinished)
        .map((entry) => entry.video.id);

    if (ids.length === 0) {
        return;
    }

    const query = new URLSearchParams(ids.map((id) => ['ids[]', String(id)]));
    const response = await fetch(`/videos/lookup?${query}`, {
        headers: { Accept: 'application/json' },
        signal,
    });

    if (!response.ok) {
        return;
    }

    const { data } = (await response.json()) as { data: Video[] };
    const found = new Map(data.map((video) => [video.id, video]));
    const next = { ...read() };

    for (const id of ids) {
        const video = found.get(id);

        if (video) {
            next[id] = { ...next[id], video: snapshot(video) };
        } else {
            delete next[id];
        }
    }

    write(next);
}

export function useContinueWatching(limit = 20): ProgressEntry[] {
    const store = useProgressStore();

    useEffect(() => {
        const controller = new AbortController();
        // Нет сети — покажем то, что есть.
        syncWithLibrary(controller.signal).catch(() => {});

        return () => controller.abort();
    }, []);

    return Object.values(store)
        .filter(isUnfinished)
        .sort((a, b) => b.updatedAt - a.updatedAt)
        .slice(0, limit);
}
