import { Head, Link, router, usePoll } from "@inertiajs/react";
import { ArrowDownToLine, X } from "lucide-react";
import { useState } from "react";
import { EmptyState, PageTitle } from "@/components/empty-state";
import { Button } from "@/components/ui/button";
import { formatDuration, formatNumber } from "@/lib/format";
import { cn } from "@/lib/utils";
import type { Video } from "@/types";

type QueueEntry = Video & { retry_pending: boolean };

interface Snapshot {
    current: Video | null;
    started_at: string | null;
    queue: QueueEntry[];
    queued_count: number;
    page: number;
    pages: number;
}

/** «1 мин», «12 мин» — сколько уже идёт текущая загрузка. */
function elapsed(startedAt: string | null): string {
    if (!startedAt) return "";
    const minutes = Math.floor(
        (Date.now() - new Date(startedAt).getTime()) / 60_000,
    );

    return minutes < 1 ? "только что" : `${minutes} мин`;
}

function Thumbnail({ video, className }: { video: Video; className?: string }) {
    return video.thumbnail ? (
        <img
            src={video.thumbnail}
            alt=""
            className={cn(
                "aspect-video shrink-0 rounded-lg bg-muted object-cover",
                className,
            )}
        />
    ) : (
        <span
            className={cn(
                "aspect-video shrink-0 rounded-lg bg-muted",
                className,
            )}
        />
    );
}

function CurrentDownload({
    video,
    startedAt,
}: {
    video: Video;
    startedAt: string | null;
}) {
    return (
        <Link
            href={`/watch/${video.id}`}
            className="flex items-center gap-3 rounded-xl p-2.5 transition hover:bg-accent"
        >
            <span className="relative w-28 shrink-0 overflow-hidden rounded-lg">
                <Thumbnail video={video} className="w-full" />
                {/* Процентов воркер не сообщает — полоса без конца, как у iOS. */}
                <span className="absolute inset-x-0 bottom-0 h-1 overflow-hidden bg-black/40">
                    <span className="animate-indeterminate block h-full w-1/3 rounded-full bg-brand" />
                </span>
            </span>
            <span className="min-w-0 flex-1">
                <span className="flex items-center gap-1 text-xs font-medium text-brand">
                    <ArrowDownToLine className="size-3.5 animate-pulse" />
                    Скачивается
                    {startedAt && (
                        <span className="font-normal text-muted-foreground">
                            · {elapsed(startedAt)}
                        </span>
                    )}
                </span>
                <span className="mt-0.5 line-clamp-2 text-sm leading-snug font-semibold">
                    {video.name}
                </span>
                {video.channel && (
                    <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                        {video.channel.name}
                    </span>
                )}
            </span>
        </Link>
    );
}

function QueuedDownload({ video }: {
    video: QueueEntry;
}) {
    const [cancelling, setCancelling] = useState(false);
    // Как в меню карточки: отменить можно только свой запрос.
    const canCancel = !!video.download_requested_at && !video.auto_download;

    const reason = video.download_requested_at ? "По запросу" : "Автозагрузка";
    const cancel = () =>
        router.delete(`/videos/${video.id}/download`, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setCancelling(true),
            onFinish: () => setCancelling(false),
        });

    return (
        <div
            className={cn(
                "group/row relative flex items-center gap-3 rounded-xl p-2 transition hover:bg-accent",
                cancelling && "opacity-50",
            )}
        >
            <Link
                href={`/watch/${video.id}`}
                className="flex min-w-0 flex-1 items-center gap-3"
            >
                <Thumbnail video={video} className="w-20" />
                <span className="min-w-0 flex-1">
                    <span className="line-clamp-2 text-[13px] leading-snug font-medium">
                        {video.name}
                    </span>
                    <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                        {video.retry_pending ? "Повтор после ошибки" : reason}
                        {video.duration_seconds > 0 &&
                            ` · ${formatDuration(video.duration_seconds)}`}
                        {video.channel && ` · ${video.channel.name}`}
                    </span>
                </span>
            </Link>
            {canCancel && (
                <Button
                    variant="ghost"
                    size="icon"
                    disabled={cancelling}
                    onClick={cancel}
                    aria-label="Отменить загрузку"
                    className="size-11 shrink-0 rounded-full text-muted-foreground hover:text-foreground"
                >
                    <X className="size-4" />
                </Button>
            )}
        </div>
    );
}

export default function Downloads({ snapshot }: { snapshot: Snapshot }) {
    usePoll(5000, { only: ["snapshot"] });
    const { current, started_at, queue, queued_count, page, pages } = snapshot;
    return (
        <div className="mx-auto max-w-3xl">
            <Head title="Очередь сервера" />
            <Link href="/statistics" className="mb-2 inline-flex min-h-11 items-center text-sm text-muted-foreground">← Статистика</Link>
            <PageTitle>Очередь сервера</PageTitle>
            {current && <CurrentDownload video={current} startedAt={started_at} />}
            {queue.length > 0 && (
                <section className="mt-4">
                    <h2 className="mb-2 font-display text-lg font-semibold">В очереди · {formatNumber(queued_count)}</h2>
                    <ul>{queue.map(video => <li key={video.id}><QueuedDownload video={video} /></li>)}</ul>
                </section>
            )}
            {!current && !queued_count && <EmptyState icon={ArrowDownToLine} title="Очередь пуста">Выберите «Скачать на сервер» в меню видео.</EmptyState>}
            {pages > 1 && (
                <nav aria-label="Страницы очереди" className="mt-4 flex items-center justify-between gap-2 text-sm">
                    {page > 1 ? <Button asChild variant="outline"><Link href={`/downloads?page=${page - 1}`}>Назад</Link></Button> : <span />}
                    <span>{page} из {pages}</span>
                    {page < pages ? <Button asChild variant="outline"><Link href={`/downloads?page=${page + 1}`}>Далее</Link></Button> : <span />}
                </nav>
            )}
        </div>
    );
}
