import { useSyncExternalStore } from 'react';

/** Уведомление колокольчика — App\Http\Controllers\Web\NotificationController::present(). */
export interface AppNotification {
    id: string;
    type: 'video-ready' | string;
    read_at: string | null;
    created_at: string;
    video_id: number;
    title: string;
    thumbnail: string | null;
    channel_id: number | null;
    channel_name: string | null;
}

interface State {
    /** null — ещё не знаем: значение придёт из общих пропсов Inertia. */
    unread: number | null;
    items: AppNotification[];
    loaded: boolean;
}

let state: State = { unread: null, items: [], loaded: false };
const listeners = new Set<() => void>();

function set(next: Partial<State>) {
    state = { ...state, ...next };
    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

export function useNotifications(): State {
    return useSyncExternalStore(subscribe, () => state, () => state);
}

/** Счётчик с сервера (общие пропсы) — он главнее того, что насчитали по событиям. */
export function syncUnread(unread: number) {
    if (state.unread !== unread) set({ unread });
}

/** Запросы с CSRF-токеном из cookie XSRF-TOKEN, как это делает сам Inertia. */
async function send(method: 'GET' | 'POST' | 'PATCH' | 'DELETE', url: string): Promise<Response> {
    const token = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1];

    return fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
        },
    });
}

export async function loadNotifications() {
    const response = await send('GET', '/notifications');
    if (!response.ok) return;

    const body = (await response.json()) as { unread_count: number; data: AppNotification[] };
    set({ items: body.data, unread: body.unread_count, loaded: true });
}

/** Новое из Reverb: в начало списка, счётчик +1. */
export function pushNotification(notification: AppNotification) {
    if (state.items.some((item) => item.id === notification.id)) return;

    set({ items: [notification, ...state.items], unread: (state.unread ?? 0) + 1 });
}

export async function markRead(id: string) {
    const item = state.items.find((candidate) => candidate.id === id);
    if (item && item.read_at) return;

    set({
        items: state.items.map((candidate) => (candidate.id === id ? { ...candidate, read_at: new Date().toISOString() } : candidate)),
        unread: Math.max((state.unread ?? 1) - 1, 0),
    });
    await send('PATCH', `/notifications/${id}`);
}

export async function markAllRead() {
    const now = new Date().toISOString();
    set({ items: state.items.map((item) => ({ ...item, read_at: item.read_at ?? now })), unread: 0 });
    await send('POST', '/notifications/read');
}

export async function clearNotifications() {
    set({ items: [], unread: 0 });
    await send('DELETE', '/notifications');
}
