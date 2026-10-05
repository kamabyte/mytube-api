import { router } from "@inertiajs/react";
import { useEchoPublic } from "@laravel/echo-react";
import { CircleCheck, X } from "lucide-react";
import { toast } from "sonner";
import { openNotification } from "@/components/notification-bell";
import { type AppNotification, pushNotification } from "@/lib/notifications";

/** App\Notifications\VideoReady::CHANNEL */
const NOTIFICATIONS_CHANNEL = "notifications";

/** Событие Reverb (BroadcastNotificationCreated): данные уведомления + id и type. */
type BroadcastNotification = Omit<AppNotification, "read_at" | "created_at">;

function VideoReadyToast({
    id,
    notification,
}: {
    id: string | number;
    notification: AppNotification;
}) {
    return (
        <div className="relative flex w-[var(--width)] max-w-full items-center gap-3 rounded-[14px] border border-border bg-popover p-2.5 pr-8 text-popover-foreground shadow-lg">
            <button
                type="button"
                onClick={() => {
                    toast.dismiss(id);
                    openNotification(notification);
                }}
                className="flex min-w-0 flex-1 items-center gap-3 text-left focus-visible:outline-none"
            >
                {notification.thumbnail ? (
                    <img
                        src={notification.thumbnail}
                        alt=""
                        className="aspect-video w-24 shrink-0 rounded-lg bg-muted object-cover"
                    />
                ) : (
                    <span className="aspect-video w-24 shrink-0 rounded-lg bg-muted" />
                )}
                <span className="min-w-0">
                    <span className="flex items-center gap-1 text-xs font-medium text-brand">
                        <CircleCheck className="size-3.5" />
                        Видео готово
                    </span>
                    <span className="mt-0.5 line-clamp-2 text-sm leading-snug font-semibold">
                        {notification.title}
                    </span>
                    {notification.channel_name && (
                        <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                            {notification.channel_name}
                        </span>
                    )}
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

function Listener() {
    useEchoPublic<BroadcastNotification>(
        NOTIFICATIONS_CHANNEL,
        ".notification.created",
        (payload) => {
            const notification: AppNotification = {
                ...payload,
                read_at: null,
                created_at: new Date().toISOString(),
            };

            pushNotification(notification);
            // Видео скачалось — очередь в шапке стала короче.
            router.reload({ only: ["downloads"] });
            toast.custom(
                (id) => <VideoReadyToast id={id} notification={notification} />,
                { duration: 10_000 },
            );
        },
    );

    return null;
}

/**
 * Живые уведомления через Reverb: в колокольчик и всплывашкой, на любой странице.
 * Что пришло, пока вкладки не было, ждёт в колокольчике (непрочитанные хранятся на сервере).
 */
export function LiveNotifications({ live }: { live: boolean }) {
    return live ? <Listener /> : null;
}
