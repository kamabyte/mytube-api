import { Link, usePage } from "@inertiajs/react";
import {
    ChartColumnBig,
    Clapperboard,
    HardDrive,
    House,
    type LucideIcon,
    PanelLeftClose,
    PanelLeftOpen,
    Search,
    Tv,
} from "lucide-react";
import type { ReactNode } from "react";
import { AppearanceMenu } from "@/components/appearance-menu";
import { DownloadsMenu } from "@/components/downloads-menu";
import { NetworkStatus } from "@/components/network-status";
import { PullToRefresh } from "@/components/pull-to-refresh";
import { NotificationBell } from "@/components/notification-bell";
import { ChannelAvatar } from "@/components/channel-avatar";
import { Logo, LogoMark } from "@/components/logo";
import { SearchBox } from "@/components/search-box";
import { Button } from "@/components/ui/button";
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from "@/components/ui/tooltip";
import { useStoredToggle } from "@/hooks/use-stored-toggle";
import { useChannelViews } from "@/lib/channel-views";
import { DEVICE_LIBRARY } from "@/lib/offline-video";
import { cn } from "@/lib/utils";
import type { SharedProps } from "@/types";

interface NavItem {
    href: string;
    label: string;
    icon: LucideIcon;
    shortLabel?: string;
    /** Какие адреса подсвечивают пункт, кроме самого href. */
    match?: RegExp;
}

const NAV: NavItem[] = [
    { href: "/", label: "Главная", icon: House, match: /^\/(\?.*)?$/ },
    {
        href: "/videos",
        label: "Все видео",
        icon: Clapperboard,
        match: /^\/(videos|watch)\b/,
    },
    { href: "/channels", label: "Каналы", icon: Tv, match: /^\/channels\b/ },
    { href: DEVICE_LIBRARY, label: "На устройстве", shortLabel: "Офлайн", icon: HardDrive },
    { href: "/statistics", label: "Статистика", icon: ChartColumnBig },
];

function isActive(item: NavItem, url: string) {
    return item.match ? item.match.test(url) : url.startsWith(item.href);
}

function Sidebar({ compact }: { compact: boolean }) {
    const { url, props } = usePage<SharedProps>();
    const views = useChannelViews();
    const channels = [...(props.sidebarChannels ?? [])].sort(
        (a, b) => (views[b.id] ?? 0) - (views[a.id] ?? 0),
    );

    return (
        <aside id="app-sidebar" className="fixed inset-y-0 left-[env(safe-area-inset-left)] z-30 hidden w-(--sidebar-width) flex-col border-r border-border/60 bg-background pt-[env(safe-area-inset-top)] md:flex">
            <div className={cn("flex h-16 shrink-0 items-center justify-center px-5", !compact && "lg:justify-start")}>
                <Link
                    href="/"
                    className="rounded-lg focus-visible:ring-2 focus-visible:ring-ring"
                    aria-label="MyTube — главная"
                >
                    <LogoMark className={cn(!compact && "lg:hidden")} />
                    {!compact && <Logo className="hidden lg:inline-flex" />}
                </Link>
            </div>

            <nav className="flex flex-col gap-0.5 px-3" aria-label="Разделы">
                {NAV.map((item) => {
                    const active = isActive(item, url);
                    const Icon = item.icon;
                    const NavLink = item.href === DEVICE_LIBRARY ? "a" : Link;

                    return (
                        <Tooltip key={item.href}>
                            <TooltipTrigger asChild>
                                <NavLink
                                    href={item.href}
                                    {...(item.href === DEVICE_LIBRARY ? {} : { prefetch: true })}
                                    aria-label={item.label}
                                    aria-current={active ? "page" : undefined}
                                    className={cn(
                                        "group flex h-11 items-center justify-center gap-3.5 rounded-xl px-3 text-[15px] font-medium text-muted-foreground transition-colors hover:bg-accent/70 hover:text-foreground",
                                        !compact && "lg:justify-start",
                                        active && "bg-accent text-foreground",
                                    )}
                                >
                                    <Icon
                                        className={cn(
                                            "size-5 shrink-0",
                                            active && "text-brand",
                                        )}
                                        strokeWidth={active ? 2.25 : 1.9}
                                    />
                                    {!compact && <span className="hidden lg:inline">{item.label}</span>}
                                </NavLink>
                            </TooltipTrigger>
                            <TooltipContent side="right" className={cn(!compact && "lg:hidden")}>
                                {item.label}
                            </TooltipContent>
                        </Tooltip>
                    );
                })}
            </nav>

            {channels.length > 0 && (
                <nav aria-label="Подписки" className="mt-6 hidden min-h-0 flex-1 flex-col lg:flex">
                    {!compact && (
                        <div className="px-6 pb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Подписки
                        </div>
                    )}
                    <div className="scrollbar-none min-h-0 flex-1 overflow-y-auto px-3 pb-6">
                        {channels.map((channel) => {
                            const href = `/channels/${channel.id}`;
                            const active = url.split("?")[0] === href;

                            return (
                                <Tooltip key={channel.id}>
                                    <TooltipTrigger asChild>
                                        <Link
                                            href={href}
                                            aria-label={channel.name}
                                            aria-current={active ? "page" : undefined}
                                            className={cn(
                                                "flex h-10 items-center gap-3 rounded-xl px-3 text-sm text-muted-foreground transition-colors hover:bg-accent/70 hover:text-foreground",
                                                compact && "justify-center",
                                                active && "bg-accent text-foreground",
                                            )}
                                        >
                                            <ChannelAvatar channel={channel} className="size-6 shrink-0" />
                                            {!compact && <span className="truncate">{channel.name}</span>}
                                        </Link>
                                    </TooltipTrigger>
                                    {compact && <TooltipContent side="right">{channel.name}</TooltipContent>}
                                </Tooltip>
                            );
                        })}
                    </div>
                </nav>
            )}
        </aside>
    );
}

function Header({ sidebarCompact, onToggleSidebar }: { sidebarCompact: boolean; onToggleSidebar: () => void }) {
    const sidebarLabel = sidebarCompact ? "Развернуть сайдбар" : "Свернуть сайдбар";

    return (
        <header className="sticky top-0 z-20 border-b border-border/60 bg-chrome pt-[env(safe-area-inset-top)] backdrop-blur-xl backdrop-saturate-150">
            <div className="flex h-14 items-center gap-3 px-4 md:h-16 md:px-6 lg:px-8">
                <Link
                    href="/"
                    className="md:hidden"
                    aria-label="MyTube — главная"
                >
                    <Logo />
                </Link>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="hidden rounded-full text-muted-foreground lg:inline-flex"
                    aria-controls="app-sidebar"
                    aria-expanded={!sidebarCompact}
                    aria-label={sidebarLabel}
                    title={sidebarLabel}
                    onClick={onToggleSidebar}
                >
                    {sidebarCompact ? <PanelLeftOpen aria-hidden /> : <PanelLeftClose aria-hidden />}
                </Button>
                <SearchBox className="hidden max-w-xl md:block" />
                <div className="ml-auto flex items-center sm:gap-1">
                    <Button
                        asChild
                        variant="ghost"
                        size="icon"
                        className="size-11 rounded-full md:hidden"
                    >
                        <Link href="/search" aria-label="Поиск">
                            <Search className="size-[18px]" />
                        </Link>
                    </Button>
                    <DownloadsMenu />
                    <NotificationBell />
                    <AppearanceMenu />
                </div>
            </div>
        </header>
    );
}

/** Нижняя панель вкладок на телефоне — как в iOS. */
function TabBar() {
    const { url } = usePage();

    return (
        <nav
            className="pb-safe fixed inset-x-0 bottom-0 z-30 border-t border-border/60 bg-chrome backdrop-blur-xl backdrop-saturate-150 md:hidden"
            aria-label="Разделы"
        >
            <div className="grid h-14 grid-cols-5 pr-[env(safe-area-inset-right)] pl-[env(safe-area-inset-left)]">
                {NAV.map((item) => {
                    const active = isActive(item, url);
                    const Icon = item.icon;
                    const NavLink = item.href === DEVICE_LIBRARY ? "a" : Link;

                    return (
                        <NavLink
                            key={item.href}
                            href={item.href}
                            aria-label={item.label}
                            aria-current={active ? "page" : undefined}
                            className={cn(
                                "flex flex-col items-center justify-center gap-0.5 px-0.5 text-center text-xs font-medium text-muted-foreground",
                                active && "text-brand",
                            )}
                        >
                            <Icon
                                className="size-[22px]"
                                strokeWidth={active ? 2.2 : 1.8}
                            />
                            {item.shortLabel ?? item.label}
                        </NavLink>
                    );
                })}
            </div>
        </nav>
    );
}

export default function AppLayout({ children }: { children: ReactNode }) {
    const [sidebarCompact, setSidebarCompact] = useStoredToggle("mytube:sidebar-compact", false);

    return (
        <div className={cn(
            "min-h-dvh pr-[env(safe-area-inset-right)] pl-[env(safe-area-inset-left)] md:[--sidebar-width:76px]",
            !sidebarCompact && "lg:[--sidebar-width:16rem]",
        )}>
            <Sidebar compact={sidebarCompact} />
            <div className="md:pl-(--sidebar-width)">
                <Header sidebarCompact={sidebarCompact} onToggleSidebar={() => setSidebarCompact(!sidebarCompact)} />
                <NetworkStatus />
                <PullToRefresh />
                <main data-pull-to-refresh className="px-4 pt-4 pb-[calc(5rem+env(safe-area-inset-bottom))] md:px-6 md:pt-6 md:pb-[max(3rem,env(safe-area-inset-bottom))] lg:px-8">
                    {children}
                </main>
            </div>
            <TabBar />
        </div>
    );
}
