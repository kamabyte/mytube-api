import { Link, usePage } from "@inertiajs/react";
import {
    ChartColumnBig,
    Clapperboard,
    House,
    type LucideIcon,
    Search,
    Tv,
} from "lucide-react";
import type { ReactNode } from "react";
import { AppearanceMenu } from "@/components/appearance-menu";
import { DownloadsMenu } from "@/components/downloads-menu";
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
import { cn } from "@/lib/utils";
import type { SharedProps } from "@/types";

interface NavItem {
    href: string;
    label: string;
    icon: LucideIcon;
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
    { href: "/statistics", label: "Статистика", icon: ChartColumnBig },
];

function isActive(item: NavItem, url: string) {
    return item.match ? item.match.test(url) : url.startsWith(item.href);
}

function Sidebar() {
    const { url, props } = usePage<SharedProps>();
    const channels = props.sidebarChannels ?? [];

    return (
        <aside className="fixed inset-y-0 left-0 z-30 hidden w-[76px] flex-col border-r border-border/60 bg-background md:flex lg:w-64">
            <div className="flex h-16 shrink-0 items-center justify-center px-5 lg:justify-start">
                <Link
                    href="/"
                    className="rounded-lg focus-visible:ring-2 focus-visible:ring-ring"
                    aria-label="MyTube — главная"
                >
                    <LogoMark className="lg:hidden" />
                    <Logo className="hidden lg:inline-flex" />
                </Link>
            </div>

            <nav className="flex flex-col gap-0.5 px-3" aria-label="Разделы">
                {NAV.map((item) => {
                    const active = isActive(item, url);
                    const Icon = item.icon;

                    return (
                        <Tooltip key={item.href}>
                            <TooltipTrigger asChild>
                                <Link
                                    href={item.href}
                                    prefetch
                                    aria-current={active ? "page" : undefined}
                                    className={cn(
                                        "group flex h-11 items-center justify-center gap-3.5 rounded-xl px-3 text-[15px] font-medium text-muted-foreground transition-colors hover:bg-accent/70 hover:text-foreground lg:justify-start",
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
                                    <span className="hidden lg:inline">
                                        {item.label}
                                    </span>
                                </Link>
                            </TooltipTrigger>
                            <TooltipContent side="right" className="lg:hidden">
                                {item.label}
                            </TooltipContent>
                        </Tooltip>
                    );
                })}
            </nav>

            {channels.length > 0 && (
                <div className="mt-6 hidden min-h-0 flex-1 flex-col lg:flex">
                    <div className="px-6 pb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Подписки
                    </div>
                    <div className="scrollbar-none min-h-0 flex-1 overflow-y-auto px-3 pb-6">
                        {channels.map((channel) => {
                            const href = `/channels/${channel.id}`;
                            const active = url.split("?")[0] === href;

                            return (
                                <Link
                                    key={channel.id}
                                    href={href}
                                    className={cn(
                                        "flex h-10 items-center gap-3 rounded-xl px-3 text-sm text-muted-foreground transition-colors hover:bg-accent/70 hover:text-foreground",
                                        active && "bg-accent text-foreground",
                                    )}
                                >
                                    <ChannelAvatar
                                        channel={channel}
                                        className="size-6"
                                    />
                                    <span className="truncate">
                                        {channel.name}
                                    </span>
                                </Link>
                            );
                        })}
                    </div>
                </div>
            )}
        </aside>
    );
}

function Header() {
    return (
        <header className="sticky top-0 z-20 border-b border-border/60 bg-chrome backdrop-blur-xl backdrop-saturate-150">
            <div className="flex h-14 items-center gap-3 px-4 md:h-16 md:px-6 lg:px-8">
                <Link
                    href="/"
                    className="md:hidden"
                    aria-label="MyTube — главная"
                >
                    <Logo />
                </Link>
                <SearchBox className="hidden max-w-xl md:block" />
                <div className="ml-auto flex items-center gap-1">
                    <Button
                        asChild
                        variant="ghost"
                        size="icon"
                        className="rounded-full md:hidden"
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
            <div className="grid h-14 grid-cols-4">
                {NAV.map((item) => {
                    const active = isActive(item, url);
                    const Icon = item.icon;

                    return (
                        <Link
                            key={item.href}
                            href={item.href}
                            aria-current={active ? "page" : undefined}
                            className={cn(
                                "flex flex-col items-center justify-center gap-0.5 text-[10.5px] font-medium text-muted-foreground",
                                active && "text-brand",
                            )}
                        >
                            <Icon
                                className="size-[22px]"
                                strokeWidth={active ? 2.2 : 1.8}
                            />
                            {item.label}
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}

export default function AppLayout({ children }: { children: ReactNode }) {
    return (
        <div className="min-h-dvh">
            <Sidebar />
            <div className="md:pl-[76px] lg:pl-64">
                <Header />
                <main className="px-4 pt-4 pb-28 md:px-6 md:pt-6 md:pb-12 lg:px-8">
                    {children}
                </main>
            </div>
            <TabBar />
        </div>
    );
}
