import { router } from '@inertiajs/react';
import { Ban, Clock, CloudDownload, Loader2, type LucideIcon } from 'lucide-react';
import { useEffect } from 'react';
import { cn } from '@/lib/utils';
import type { DownloadState, VideoDetail } from '@/types';

/** Как часто спрашивать, не скачалось ли: час видео — 3–4 минуты. */
const POLL_MS = 5000;

const COPY: Record<Exclude<DownloadState, 'downloaded'>, { icon: LucideIcon; title: string; text: string }> = {
    available: {
        icon: CloudDownload,
        title: 'Видео ещё не скачано на сервер',
        text: 'Запустите скачивание из меню рядом с названием видео.',
    },
    queued: {
        icon: Clock,
        title: 'В очереди на сервере',
        text: 'Когда файл будет готов, здесь появится плеер.',
    },
    downloading: {
        icon: Loader2,
        title: 'Скачивается на сервер…',
        text: 'Обычно это пара минут. Плеер откроется сам.',
    },
    unavailable: {
        icon: Ban,
        title: 'Видео недоступно',
        text: 'YouTube его не отдаёт: удалено, закрыто или недоступно в регионе.',
    },
};

/**
 * Вместо плеера у видео из каталога отображается статус скачивания на сервер.
 * Пока видео в очереди, страница переспрашивает сервер и, когда файл готов,
 * получает stream_url — тогда страница сама покажет плеер.
 */
export function DownloadStage({ video }: { video: VideoDetail }) {
    const state = video.download_state === 'downloaded' ? 'queued' : video.download_state;
    const waiting = state === 'queued' || state === 'downloading';
    const copy = COPY[state];

    useEffect(() => {
        if (!waiting) return;
        const timer = window.setInterval(() => router.reload({ only: ['video'] }), POLL_MS);
        return () => window.clearInterval(timer);
    }, [waiting]);

    return (
        <div className="relative isolate flex aspect-video w-full items-center justify-center overflow-hidden bg-black text-white shadow-2xl shadow-black/20 sm:rounded-2xl dark:shadow-black/60">
            {video.thumbnail && (
                <img src={video.thumbnail} alt="" aria-hidden className="absolute inset-0 -z-10 size-full scale-105 object-cover opacity-35 blur-sm" />
            )}
            <div className="absolute inset-0 -z-10 bg-gradient-to-t from-black/70 via-black/30 to-black/10" />

            <div className="flex max-w-md flex-col items-center gap-3 px-6 text-center">
                <span className="flex size-14 items-center justify-center rounded-full bg-white/15 backdrop-blur">
                    <copy.icon className={cn('size-7', state === 'downloading' && 'animate-spin')} />
                </span>
                <h2 className="font-display text-xl font-semibold md:text-2xl">{copy.title}</h2>
                <p className="text-sm text-white/75 md:text-[15px]">
                    {state === 'queued' && video.auto_download ? 'Канал скачивается целиком — видео дождётся своей очереди.' : copy.text}
                </p>
            </div>
        </div>
    );
}
