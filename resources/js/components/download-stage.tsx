import { router } from '@inertiajs/react';
import { Ban, Clock, CloudDownload, ExternalLink, Loader2, type LucideIcon, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { DownloadState, VideoDetail } from '@/types';

/** Как часто спрашивать, не скачалось ли: час видео — 3–4 минуты. */
const POLL_MS = 5000;

const COPY: Record<Exclude<DownloadState, 'downloaded'>, { icon: LucideIcon; title: string; text: string }> = {
    available: {
        icon: CloudDownload,
        title: 'Видео ещё не скачано',
        text: 'Час видео скачивается за 3–4 минуты. Как только файл будет готов, здесь появится плеер.',
    },
    queued: {
        icon: Clock,
        title: 'В очереди на загрузку',
        text: 'Воркер возьмёт его, как только освободится. Страница обновится сама.',
    },
    downloading: {
        icon: Loader2,
        title: 'Скачивается…',
        text: 'Обычно это пара минут. Плеер откроется сам.',
    },
    unavailable: {
        icon: Ban,
        title: 'Видео недоступно',
        text: 'YouTube его не отдаёт: удалено, закрыто или недоступно в регионе.',
    },
};

/**
 * Вместо плеера у видео из каталога: статус загрузки и кнопка «Скачать в медиатеку».
 * Пока видео в очереди, страница переспрашивает сервер и, когда файл готов,
 * получает stream_url — тогда страница сама покажет плеер.
 */
export function DownloadStage({ video }: { video: VideoDetail }) {
    const state = video.download_state === 'downloaded' ? 'queued' : video.download_state;
    const waiting = state === 'queued' || state === 'downloading';
    const [sending, setSending] = useState(false);
    const copy = COPY[state];
    const canCancel = state === 'queued' && !!video.download_requested_at && !video.auto_download;

    useEffect(() => {
        if (!waiting) return;
        const timer = window.setInterval(() => router.reload({ only: ['video'] }), POLL_MS);
        return () => window.clearInterval(timer);
    }, [waiting]);

    const send = (method: 'post' | 'delete') =>
        router.visit(`/videos/${video.id}/download`, {
            method,
            preserveScroll: true,
            onStart: () => setSending(true),
            onFinish: () => setSending(false),
        });

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

                <div className="mt-2 flex flex-wrap justify-center gap-2">
                    {state === 'available' && (
                        <Button className="rounded-full" disabled={sending} onClick={() => send('post')}>
                            {sending ? <Loader2 className="animate-spin" /> : <CloudDownload />}
                            Скачать в медиатеку
                        </Button>
                    )}
                    {canCancel && (
                        <Button variant="secondary" className="rounded-full" disabled={sending} onClick={() => send('delete')}>
                            <X />
                            Отменить
                        </Button>
                    )}
                    {state === 'unavailable' && (
                        <Button asChild variant="secondary" className="rounded-full">
                            <a href={`https://www.youtube.com/watch?v=${video.external_id}`} target="_blank" rel="noreferrer noopener">
                                <ExternalLink />
                                Открыть на YouTube
                            </a>
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}
