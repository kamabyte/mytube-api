import { Head, Link } from '@inertiajs/react';
import { CalendarPlus, Hand, ListVideo, Pause, Tv } from 'lucide-react';
import { AddChannelDialog } from '@/components/add-channel-dialog';
import { ChannelAvatar } from '@/components/channel-avatar';
import { ChannelMenu } from '@/components/channel-menu';
import { CatalogSwitch } from '@/components/catalog-switch';
import { EmptyState, PageTitle } from '@/components/empty-state';
import { formatBytes, formatRelative, plural } from '@/lib/format';
import type { ChannelSummary } from '@/types';

type Channel = ChannelSummary & { youtube_url: string };

function ChannelCard({ channel }: { channel: Channel }) {
    const mode = channel.sync_paused
        ? { icon: Pause, label: 'На паузе' }
        : channel.download_on_demand
            ? { icon: Hand, label: 'По запросу' }
            : channel.auto_download_from
                ? { icon: CalendarPlus, label: 'Только новые' }
                : null;
    const statuses = [mode, channel.is_playlist ? { icon: ListVideo, label: 'Плейлист' } : null]
        .filter((status) => status !== null);
    const statusId = statuses.length ? `channel-status-${channel.id}` : undefined;

    return (
        <article className="animate-fade-in relative min-w-0 transition motion-safe:hover:-translate-y-0.5">
            <Link
                href={`/channels/${channel.id}`}
                aria-label={channel.name}
                aria-describedby={statusId}
                className="pressable relative flex h-full flex-col items-center gap-3 overflow-hidden rounded-2xl border border-border/60 bg-card px-3 pt-10 pb-5 text-center transition-[background-color,box-shadow,opacity,scale] duration-150 hover:shadow-xl hover:shadow-black/5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:bg-accent data-loading:bg-accent data-pressed:bg-accent sm:px-4 dark:hover:shadow-black/40"
            >
                {channel.thumbnail && (
                    <img
                        src={channel.thumbnail}
                        alt=""
                        aria-hidden
                        className="pointer-events-none absolute inset-x-0 top-0 h-24 w-full scale-150 object-cover opacity-25 blur-2xl dark:opacity-30"
                    />
                )}
                {statusId && (
                    <div className="absolute top-3 left-3 flex gap-1">
                        <span id={statusId} className="sr-only">{statuses.map(({ label }) => label).join(', ')}</span>
                        {statuses.map(({ icon: Icon, label }) => (
                            <span key={label} title={label} aria-hidden className="grid size-6 place-items-center rounded-full bg-background/75 text-muted-foreground">
                                <Icon className="size-3.5" />
                            </span>
                        ))}
                    </div>
                )}
                <ChannelAvatar channel={channel} className="relative size-20 shadow-lg md:size-24" />
                <div className="relative min-w-0">
                    <h2 className="line-clamp-2 font-semibold leading-snug [overflow-wrap:anywhere]">{channel.name}</h2>
                    <p className="mt-1 text-[13px] text-muted-foreground">
                        {plural(channel.videos_count ?? 0, ['видео', 'видео', 'видео'])}
                        {channel.total_size_bytes ? ` · ${formatBytes(channel.total_size_bytes)}` : ''}
                    </p>
                    {channel.latest_published_at && (
                        <time dateTime={channel.latest_published_at} className="mt-0.5 block text-xs text-muted-foreground">
                            {formatRelative(channel.latest_published_at)}
                        </time>
                    )}
                </div>
            </Link>
            <div className="absolute top-1 right-1">
                <ChannelMenu channel={channel} cardYoutubeUrl={channel.youtube_url} />
            </div>
        </article>
    );
}

export default function Channels({ channels }: { channels: Channel[] }) {
    return (
        <>
            <Head title="Каналы" />
            <PageTitle actions={<AddChannelDialog />}>
                <span className="md:hidden">Каталог</span>
                <span className="hidden md:inline">Каналы</span>
                <span className="ml-3 align-middle text-base font-normal text-muted-foreground">{channels.length}</span>
            </PageTitle>
            <CatalogSwitch current="/channels" />

            {channels.length === 0 ? (
                <EmptyState icon={Tv} title="Каналов нет">
                    Нажмите «Добавить канал» и вставьте ссылку с YouTube.
                </EmptyState>
            ) : (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:gap-4 lg:grid-cols-4 2xl:grid-cols-6">
                    {channels.map((channel) => <ChannelCard key={channel.id} channel={channel} />)}
                </div>
            )}
        </>
    );
}
