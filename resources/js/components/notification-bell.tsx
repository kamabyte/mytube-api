import { router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck, CircleCheck, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { formatRelative } from '@/lib/format';
import { type AppNotification, clearNotifications, loadNotifications, markAllRead, markRead, syncUnread, useNotifications } from '@/lib/notifications';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/** Открыть видео из уведомления — и отметить его прочитанным. */
export function openNotification(notification: AppNotification) {
    void markRead(notification.id);
    router.visit(`/watch/${notification.video_id}`);
}

function NotificationRow({ notification }: { notification: AppNotification }) {
    const unread = !notification.read_at;

    return (
        <DropdownMenuItem
            onSelect={() => openNotification(notification)}
            className={cn('items-start gap-3 rounded-xl p-2.5', unread && 'bg-brand/[0.06]')}
        >
            {notification.thumbnail ? (
                <img src={notification.thumbnail} alt="" className="aspect-video w-24 shrink-0 rounded-lg bg-muted object-cover" />
            ) : (
                <span className="aspect-video w-24 shrink-0 rounded-lg bg-muted" />
            )}
            <span className="min-w-0 flex-1">
                <span className="flex items-center gap-1 text-xs font-medium text-brand">
                    <CircleCheck className="size-3.5" />
                    Видео готово
                    <span className="font-normal text-muted-foreground">· {formatRelative(notification.created_at)}</span>
                </span>
                <span className="mt-0.5 line-clamp-2 text-sm leading-snug font-semibold">{notification.title}</span>
                {notification.channel_name && <span className="mt-0.5 block truncate text-xs text-muted-foreground">{notification.channel_name}</span>}
            </span>
            {unread && <span className="mt-1.5 size-2 shrink-0 rounded-full bg-brand" aria-label="Не прочитано" />}
        </DropdownMenuItem>
    );
}

/**
 * Колокольчик в шапке: бейдж непрочитанных (из общих пропсов, живьём — из Reverb)
 * и панель со всеми уведомлениями. Список грузится, когда панель открывают.
 */
export function NotificationBell() {
    const { unreadNotifications } = usePage<SharedProps>().props;
    const { unread, items, loaded } = useNotifications();
    const [open, setOpen] = useState(false);
    const count = unread ?? unreadNotifications ?? 0;

    useEffect(() => {
        if (typeof unreadNotifications === 'number') syncUnread(unreadNotifications);
    }, [unreadNotifications]);

    return (
        <DropdownMenu
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                if (value) void loadNotifications();
            }}
        >
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="relative rounded-full" aria-label={count ? `Уведомления: ${count} новых` : 'Уведомления'}>
                    <Bell className="size-[18px]" />
                    {count > 0 && (
                        <span className="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand px-1 text-[10px] leading-none font-bold text-white tabular-nums">
                            {count > 99 ? '99+' : count}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[min(26rem,calc(100vw-2rem))] rounded-2xl p-0">
                <div className="flex items-center justify-between gap-2 border-b border-border/60 px-4 py-3">
                    <span className="font-display text-base font-semibold">Уведомления</span>
                    <div className="flex gap-1">
                        {count > 0 && (
                            <Button variant="ghost" size="sm" className="h-8 rounded-full text-xs" onClick={() => void markAllRead()}>
                                <CheckCheck className="size-3.5" />
                                Прочитать все
                            </Button>
                        )}
                        {items.length > 0 && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8 rounded-full text-muted-foreground"
                                aria-label="Очистить уведомления"
                                onClick={() => void clearNotifications()}
                            >
                                <Trash2 className="size-3.5" />
                            </Button>
                        )}
                    </div>
                </div>

                <div className="max-h-[min(32rem,70vh)] overflow-y-auto p-1.5">
                    {items.length > 0 ? (
                        items.map((notification) => <NotificationRow key={notification.id} notification={notification} />)
                    ) : (
                        <p className="px-4 py-10 text-center text-sm text-muted-foreground">
                            {loaded ? 'Пока пусто. Здесь появятся скачанные видео.' : 'Загрузка…'}
                        </p>
                    )}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
