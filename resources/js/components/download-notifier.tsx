import { router } from '@inertiajs/react';
import { CircleCheck, X } from 'lucide-react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { plural } from '@/lib/format';
import type { Video } from '@/types';

/** Как часто открытая вкладка спрашивает, что скачалось. */
const POLL_MS = 15_000;

/** Больше за раз (скажем, целый канал) — одна сводка вместо стопки карточек. */
const MAX_CARDS = 3;

const CURSOR_KEY = 'mytube:downloads-cursor';
const SEEN_KEY = 'mytube:downloads-seen';
const SEEN_LIMIT = 50;

interface DownloadedResponse {
    now: string;
    total: number;
    data: Video[];
}

function read(key: string): string | null {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function write(key: string, value: string) {
    try {
        localStorage.setItem(key, value);
    } catch {
        // только на эту сессию
    }
}

/** id последних показанных: граница курсора включительная, повтор возможен. */
function readSeen(): number[] {
    try {
        const ids: unknown = JSON.parse(read(SEEN_KEY) ?? '[]');
        return Array.isArray(ids) ? ids.filter((id): id is number => typeof id === 'number') : [];
    } catch {
        return [];
    }
}

function DownloadToast({ id, video }: { id: string | number; video: Video }) {
    const open = () => {
        toast.dismiss(id);
        router.visit(`/watch/${video.id}`);
    };

    return (
        <div className="relative flex w-[var(--width)] max-w-full items-center gap-3 rounded-[14px] border border-border bg-popover p-2.5 pr-8 text-popover-foreground shadow-lg">
            <button type="button" onClick={open} className="flex min-w-0 flex-1 items-center gap-3 text-left focus-visible:outline-none">
                {video.thumbnail ? (
                    <img src={video.thumbnail} alt="" className="aspect-video w-24 shrink-0 rounded-lg bg-muted object-cover" />
                ) : (
                    <span className="aspect-video w-24 shrink-0 rounded-lg bg-muted" />
                )}
                <span className="min-w-0">
                    <span className="flex items-center gap-1 text-xs font-medium text-brand">
                        <CircleCheck className="size-3.5" />
                        Видео готово
                    </span>
                    <span className="mt-0.5 line-clamp-2 text-sm leading-snug font-semibold">{video.name}</span>
                    {video.channel && <span className="mt-0.5 block truncate text-xs text-muted-foreground">{video.channel.name}</span>}
                </span>
            </button>
            <button
                type="button"
                onClick={() => toast.dismiss(id)}
                aria-label="Закрыть"
                className="absolute top-2 right-2 rounded-full p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
            >
                <X className="size-3.5" />
            </button>
        </div>
    );
}

/**
 * «Видео готово» во вкладке: раз в POLL_MS и при возвращении во вкладку
 * спрашивает сервер, что скачалось с прошлого раза, — и автоматически, и по запросу.
 * Курсор в localStorage, поэтому скачанное, пока вкладка была закрыта,
 * всплывёт при следующем заходе. Скрытые вкладки не опрашивают — так несколько
 * открытых вкладок не показывают одно и то же.
 */
export function DownloadNotifier() {
    useEffect(() => {
        let busy = false;

        const check = async () => {
            if (busy || document.visibilityState !== 'visible') return;
            busy = true;

            try {
                const since = read(CURSOR_KEY);
                const url = since ? `/videos/downloaded?since=${encodeURIComponent(since)}` : '/videos/downloaded';
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!response.ok) return;

                const body = (await response.json()) as DownloadedResponse;
                write(CURSOR_KEY, body.now);

                const seen = readSeen();
                const fresh = body.data.filter((video) => !seen.includes(video.id));
                const total = body.total - (body.data.length - fresh.length);
                if (total <= 0) return;

                write(SEEN_KEY, JSON.stringify([...fresh.map((video) => video.id), ...seen].slice(0, SEEN_LIMIT)));

                if (total > MAX_CARDS) {
                    toast.success(`Скачано ${plural(total, ['видео', 'видео', 'видео'])}`, {
                        description: fresh[0] ? `Последнее — «${fresh[0].name}»` : undefined,
                        duration: 10_000,
                        action: { label: 'Смотреть', onClick: () => router.visit('/videos?sort=added') },
                    });
                    return;
                }

                // Старые первыми: свежее окажется сверху стопки.
                [...fresh].reverse().forEach((video) => toast.custom((id) => <DownloadToast id={id} video={video} />, { duration: 10_000 }));
            } catch {
                // сеть моргнула — спросим в следующий раз
            } finally {
                busy = false;
            }
        };

        void check();
        const timer = window.setInterval(() => void check(), POLL_MS);
        const onVisible = () => void check();
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, []);

    return null;
}
