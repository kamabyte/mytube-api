import { router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck, CircleCheck, Clapperboard, Trash2, X } from 'lucide-react';
import { useEffect, useState, useSyncExternalStore } from 'react';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Sheet, SheetClose, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { formatRelative } from '@/lib/format';
import { type AppNotification, clearNotifications, loadNotifications, markAllRead, markRead, syncUnread, useNotifications } from '@/lib/notifications';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/** Открыть видео из уведомления — и отметить его прочитанным. */
export function openNotification(notification: AppNotification) {
    void markRead(notification.id);
    router.visit(`/watch/${notification.video_id}`);
}

const MOBILE = '(width < 48rem)';

function subscribeToViewport(listener: () => void) {
    const query = window.matchMedia(MOBILE);
    query.addEventListener('change', listener);
    return () => query.removeEventListener('change', listener);
}

function NotificationRow({ notification, mobile, onSelect }: { notification: AppNotification; mobile: boolean; onSelect: () => void }) {
    const unread = !notification.read_at;
    const previewClass = cn('aspect-video shrink-0 rounded-lg bg-muted', mobile ? 'w-14' : 'w-24');
    const classes = cn('relative items-start rounded-xl', mobile ? 'gap-2.5 p-2' : 'gap-3 p-2.5', unread && 'bg-brand/[0.06]');
    const content = (
        <>
            {notification.thumbnail ? (
                <img src={notification.thumbnail} alt="" className={cn(previewClass, 'object-cover')} />
            ) : (
                <span className={cn(previewClass, 'grid place-items-center text-muted-foreground')}>
                    <Clapperboard className="size-4" aria-hidden />
                </span>
            )}
            <span className={cn('flex min-w-0 flex-1 flex-col', mobile ? 'gap-1' : 'gap-0.5', unread && 'pr-3')}>
                <span className={cn('leading-snug font-semibold [overflow-wrap:anywhere]', mobile ? 'text-[15px]' : 'order-2 line-clamp-2 text-sm')}>
                    {notification.title}
                </span>
                {notification.channel_name && (
                    <span className={cn('text-xs text-muted-foreground [overflow-wrap:anywhere]', mobile ? 'line-clamp-2' : 'order-3 truncate')}>
                        {notification.channel_name}
                    </span>
                )}
                <span className={cn('flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs', !mobile && 'order-1')}>
                    <span className="inline-flex items-center gap-1 font-medium text-brand">
                        <CircleCheck className="size-3.5" aria-hidden /> Видео готово
                    </span>
                    <time dateTime={notification.created_at} className="text-muted-foreground">
                        {formatRelative(notification.created_at)}
                    </time>
                </span>
            </span>
            {unread && <span className="absolute top-4 right-3 size-2 rounded-full bg-brand" role="img" aria-label="Не прочитано" />}
        </>
    );

    return mobile ? (
        <li>
            <button type="button" className={cn('pressable flex min-h-16 w-full text-left hover:bg-accent/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none', classes)} onClick={onSelect}>
                {content}
            </button>
        </li>
    ) : (
        <DropdownMenuItem className={classes} onSelect={onSelect}>{content}</DropdownMenuItem>
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
    const mobile = useSyncExternalStore(subscribeToViewport, () => window.matchMedia(MOBILE).matches, () => false);
    const count = unread ?? unreadNotifications ?? 0;

    useEffect(() => {
        if (typeof unreadNotifications === 'number') syncUnread(unreadNotifications);
    }, [unreadNotifications]);

    function changeOpen(value: boolean) {
        setOpen(value);
        if (value) void loadNotifications();
    }

    const trigger = (
        <Button variant="ghost" size="icon" className="relative size-11 rounded-full md:size-9" aria-label={count ? `Уведомления: ${count} новых` : 'Уведомления'}>
            <Bell className="size-[18px]" />
            {count > 0 && (
                <span className="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand px-1 text-[10px] leading-none font-bold text-white tabular-nums">
                    {count > 99 ? '99+' : count}
                </span>
            )}
        </Button>
    );
    const actions = (count > 0 || items.length > 0) && (
        <div className="flex gap-1">
            {count > 0 && (
                <Button variant="ghost" size="sm" className="h-11 rounded-full text-xs md:h-8" onClick={() => void markAllRead()}>
                    <CheckCheck className="size-3.5" />
                    Прочитать все
                </Button>
            )}
            {items.length > 0 && (
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-11 rounded-full text-muted-foreground md:size-8"
                    aria-label="Очистить уведомления"
                    onClick={() => void clearNotifications()}
                >
                    <Trash2 className="size-3.5" />
                </Button>
            )}
        </div>
    );
    const rows = items.map((notification) => (
        <NotificationRow key={notification.id} notification={notification} mobile={mobile} onSelect={() => {
            setOpen(false);
            openNotification(notification);
        }} />
    ));
    const empty = (
        <p className="px-4 py-10 text-center text-sm text-muted-foreground" role="status">
            {loaded ? 'Пока пусто. Здесь появятся скачанные видео.' : 'Загрузка…'}
        </p>
    );

    if (mobile) {
        return (
            <Sheet open={open} onOpenChange={changeOpen}>
                <SheetTrigger asChild>{trigger}</SheetTrigger>
                <SheetContent side="bottom" showCloseButton={false} aria-describedby={undefined} className="pb-safe max-h-[85dvh] gap-0 rounded-t-3xl pr-[env(safe-area-inset-right)] pl-[env(safe-area-inset-left)] motion-reduce:animate-none">
                    <SheetHeader className="shrink-0 gap-0 border-b border-border/60 px-4 py-2">
                        <div className="flex min-h-11 items-center justify-between gap-2">
                            <SheetTitle className="font-display text-xl">Уведомления</SheetTitle>
                            <SheetClose asChild>
                                <Button variant="ghost" size="icon" className="size-11 rounded-full" aria-label="Закрыть уведомления">
                                    <X className="size-5" />
                                </Button>
                            </SheetClose>
                        </div>
                        {actions}
                    </SheetHeader>
                    <div className="min-h-0 overflow-y-auto overscroll-contain px-3 py-2">
                        {items.length ? <ul className="space-y-1">{rows}</ul> : empty}
                    </div>
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <DropdownMenu open={open} onOpenChange={changeOpen}>
            <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[min(26rem,calc(100vw-2rem))] rounded-2xl p-0">
                <div className="flex items-center justify-between gap-2 border-b border-border/60 px-4 py-3">
                    <span className="font-display text-base font-semibold">Уведомления</span>
                    {actions}
                </div>
                <div className="max-h-[min(32rem,70dvh)] overflow-y-auto p-1.5">
                    {items.length ? rows : empty}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
