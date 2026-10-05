import { Link, router, usePage } from "@inertiajs/react";
import { ArrowDownToLine, X } from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/components/ui/popover";
import { formatDuration, formatNumber } from "@/lib/format";
import { cn } from "@/lib/utils";
import type { SharedProps, Video } from "@/types";

/** Пока панель открыта — как часто переспрашивать очередь. */
const POLL_MS = 5000;

/** Пока в очереди что-то есть — как часто обновлять бейдж на кнопке. */
const BADGE_POLL_MS = 20_000;

/** App\Http\Controllers\Web\DownloadController::index(). */
interface DownloadsPayload {
    current: Video | null;
    started_at: string | null;
    queue: Video[];
    queued_count: number;
}

async function fetchDownloads(): Promise<DownloadsPayload | null> {
    const response = await fetch("/downloads", {
        headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
        },
    });

    return response.ok ? ((await response.json()) as DownloadsPayload) : null;
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
    onNavigate,
}: {
    video: Video;
    startedAt: string | null;
    onNavigate: () => void;
}) {
    return (
        <Link
            href={`/watch/${video.id}`}
            onClick={onNavigate}
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

function QueuedDownload({
    video,
    onNavigate,
    onCancelled,
}: {
    video: Video;
    onNavigate: () => void;
    onCancelled: () => void;
}) {
    const [cancelling, setCancelling] = useState(false);
    // Как в меню карточки: отменить можно только свой запрос.
    const canCancel = !!video.download_requested_at && !video.auto_download;

    const cancel = () =>
        router.delete(`/videos/${video.id}/download`, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setCancelling(true),
            onFinish: () => setCancelling(false),
            onSuccess: onCancelled,
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
                onClick={onNavigate}
                className="flex min-w-0 flex-1 items-center gap-3"
            >
                <Thumbnail video={video} className="w-20" />
                <span className="min-w-0 flex-1">
                    <span className="line-clamp-2 text-[13px] leading-snug font-medium">
                        {video.name}
                    </span>
                    <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                        {video.download_requested_at
                            ? "По запросу"
                            : "Автозагрузка"}
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
                    className="size-8 shrink-0 rounded-full text-muted-foreground hover:text-foreground"
                >
                    <X className="size-4" />
                </Button>
            )}
        </div>
    );
}

/**
 * Кнопка «Загрузки» в шапке, рядом с колокольчиком: бейдж очереди (из общих пропсов)
 * и панель — что воркер качает сейчас и что за ним. Пока панель открыта, она
 * переспрашивает сервер; закрытая — только обновляет бейдж, если очередь не пуста.
 */
export function DownloadsMenu() {
    const { downloads } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const [data, setData] = useState<DownloadsPayload | null>(null);

    const refresh = useCallback(async () => {
        const next = await fetchDownloads();
        if (next) setData(next);
    }, []);

    useEffect(() => {
        if (!open) return;
        void refresh();
        const timer = window.setInterval(() => void refresh(), POLL_MS);
        return () => window.clearInterval(timer);
    }, [open, refresh]);

    const busy = (downloads?.queued ?? 0) > 0;
    useEffect(() => {
        if (open || !busy) return;
        const timer = window.setInterval(() => {
            if (document.visibilityState === "visible")
                router.reload({ only: ["downloads"] });
        }, BADGE_POLL_MS);
        return () => window.clearInterval(timer);
    }, [open, busy]);

    // Открытая панель знает свежее общих пропсов.
    const queued =
        open && data
            ? data.queued_count + (data.current ? 1 : 0)
            : (downloads?.queued ?? 0);
    const active = open && data ? !!data.current : !!downloads?.active;
    const rest = data ? data.queued_count - data.queue.length : 0;
    const close = () => setOpen(false);

    return (
        <Popover
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                // Закрыли — бейдж снова из общих пропсов, пусть будет свежим.
                if (!value) router.reload({ only: ["downloads"] });
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative rounded-full"
                    aria-label={
                        queued ? `Загрузки: ${queued} в очереди` : "Загрузки"
                    }
                >
                    <ArrowDownToLine
                        className={cn("size-[18px]", active && "text-brand")}
                    />
                    {active && (
                        <span className="absolute bottom-1.5 left-1/2 h-0.5 w-3 -translate-x-1/2 animate-pulse rounded-full bg-brand" />
                    )}
                    {queued > 0 && (
                        <span className="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-foreground px-1 text-[10px] leading-none font-bold text-background tabular-nums">
                            {queued > 99 ? "99+" : queued}
                        </span>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                className="w-[min(26rem,calc(100vw-2rem))] rounded-2xl p-0"
            >
                <div className="flex items-baseline justify-between gap-2 border-b border-border/60 px-4 py-3">
                    <span className="font-display text-base font-semibold">
                        Загрузки
                    </span>
                    {data && data.queued_count > 0 && (
                        <span className="text-xs text-muted-foreground tabular-nums">
                            в очереди: {formatNumber(data.queued_count)}
                        </span>
                    )}
                </div>

                <div className="max-h-[min(32rem,70vh)] overflow-y-auto p-1.5">
                    {!data ? (
                        <p className="px-4 py-10 text-center text-sm text-muted-foreground">
                            Загрузка…
                        </p>
                    ) : !data.current && data.queue.length === 0 ? (
                        <p className="px-4 py-10 text-center text-sm text-muted-foreground">
                            Сейчас ничего не скачивается. Видео из каталога
                            можно скачать из меню ⋮ на карточке.
                        </p>
                    ) : (
                        <>
                            {data.current && (
                                <CurrentDownload
                                    video={data.current}
                                    startedAt={data.started_at}
                                    onNavigate={close}
                                />
                            )}
                            {data.queue.length > 0 && (
                                <>
                                    <p className="px-2.5 pt-3 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                        Дальше
                                    </p>
                                    {data.queue.map((video) => (
                                        <QueuedDownload
                                            key={video.id}
                                            video={video}
                                            onNavigate={close}
                                            onCancelled={() => void refresh()}
                                        />
                                    ))}
                                </>
                            )}
                            {rest > 0 && (
                                <p className="px-2.5 py-2 text-xs text-muted-foreground">
                                    и ещё {formatNumber(rest)}
                                </p>
                            )}
                        </>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}
