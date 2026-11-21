import { useSyncExternalStore } from 'react';

const STORAGE_KEY = 'mytube:channel-views:v1';
const EMPTY: Readonly<Record<string, number>> = {};
const listeners = new Set<() => void>();
let counts: typeof EMPTY | undefined;

function read(): typeof EMPTY {
    try {
        const stored: unknown = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}');
        if (stored && typeof stored === 'object' && !Array.isArray(stored)) {
            return Object.fromEntries(
                Object.entries(stored).filter(([, count]) => Number.isSafeInteger(count) && count > 0),
            );
        }
    } catch {
        // Счётчики остаются доступны во вкладке, если хранилище заблокировано.
    }

    return counts ?? EMPTY;
}

function snapshot() {
    return (counts ??= read());
}

function subscribe(listener: () => void) {
    listeners.add(listener);

    const onStorage = (event: StorageEvent) => {
        if (event.key !== STORAGE_KEY && event.key !== null) return;
        counts = read();
        listener();
    };

    window.addEventListener('storage', onStorage);
    return () => {
        listeners.delete(listener);
        window.removeEventListener('storage', onStorage);
    };
}

export function useChannelViews() {
    return useSyncExternalStore(subscribe, snapshot, () => EMPTY);
}

export function recordChannelView(channelId: number) {
    const current = read();
    const previous = Math.max(current[channelId] ?? 0, counts?.[channelId] ?? 0);
    counts = { ...counts, ...current, [channelId]: previous + 1 };

    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(counts));
    } catch {
        // Сортировка продолжит работать до перезагрузки вкладки.
    }

    listeners.forEach((listener) => listener());
}
