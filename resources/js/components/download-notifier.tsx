import { router } from '@inertiajs/react';
import { useEchoPublic } from '@laravel/echo-react';
import { CircleCheck, X } from 'lucide-react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { plural } from '@/lib/format';
import type { Video } from '@/types';

/** App\Events\VideoDownloaded::CHANNEL */
const DOWNLOADS_CHANNEL = 'downloads';

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

function showSummary(total: number, latest?: Video) {
    toast.success(`Скачано ${plural(total, ['видео', 'видео', 'видео'])}`, {
        description: latest ? `Последнее — «${latest.name}»` : undefined,
        duration: 10_000,
        action: { label: 'Смотреть', onClick: () => router.visit('/videos?sort=added') },
    });
}

/** Показывает видео, если его ещё не показывали, и сдвигает курсор. */
function announce(videos: Video[], total = videos.length) {
    const seen = readSeen();
    const fresh = videos.filter((video) => !seen.includes(video.id));
    const unseen = total - (videos.length - fresh.length);
    if (unseen <= 0) return;

    write(SEEN_KEY, JSON.stringify([...fresh.map((video) => video.id), ...seen].slice(0, SEEN_LIMIT)));

    if (unseen > MAX_CARDS) {
        showSummary(unseen, fresh[0]);
        return;
    }

    // Старые первыми: свежее окажется сверху стопки.
    [...fresh].reverse().forEach((video) => toast.custom((id) => <DownloadToast id={id} video={video} />, { duration: 10_000 }));
}

/**
 * Что скачалось, пока вкладки не было: один запрос при открытии.
 * Курсор — время сервера из прошлого ответа, хранится в localStorage.
 */
async function catchUp() {
    try {
        const since = read(CURSOR_KEY);
        const url = since ? `/videos/downloaded?since=${encodeURIComponent(since)}` : '/videos/downloaded';
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;

        const body = (await response.json()) as DownloadedResponse;
        write(CURSOR_KEY, body.now);
        if (since) announce(body.data, body.total);
    } catch {
        // сеть моргнула — не страшно, живые события всё равно придут
    }
}

/** Живые события: Reverb присылает video.downloaded, как только воркер закончил. */
function LiveDownloads() {
    useEchoPublic<{ video: Video }>(DOWNLOADS_CHANNEL, '.video.downloaded', ({ video }) => {
        // downloaded_at — время сервера, как и курсор из /videos/downloaded.
        if (video.downloaded_at) write(CURSOR_KEY, video.downloaded_at);
        announce([video]);
    });

    return null;
}

/**
 * «Видео готово» во вкладке — и для автоскачивания, и для скачанного по запросу.
 * При открытии — то, что скачалось без нас (курсор в localStorage), дальше —
 * живые события через Reverb. Повторы между ними отсеиваются по id.
 */
export function DownloadNotifier({ live }: { live: boolean }) {
    useEffect(() => {
        void catchUp();
    }, []);

    return live ? <LiveDownloads /> : null;
}
