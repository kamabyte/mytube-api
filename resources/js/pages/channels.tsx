import { Head, Link } from '@inertiajs/react';
import { Hand, ListVideo, Tv } from 'lucide-react';
import { AddChannelDialog } from '@/components/add-channel-dialog';
import { ChannelAvatar } from '@/components/channel-avatar';
import { EmptyState, PageTitle } from '@/components/empty-state';
import { channelVideoCounts, formatBytes, formatRelative } from '@/lib/format';
import type { ChannelSummary } from '@/types';

export default function Channels({ channels }: { channels: ChannelSummary[] }) {
    return (
        <>
            <Head title="Каналы" />
            <PageTitle actions={<AddChannelDialog />}>
                Каналы
                <span className="ml-3 align-middle text-base font-normal text-muted-foreground">{channels.length}</span>
            </PageTitle>

            {channels.length === 0 ? (
                <EmptyState icon={Tv} title="Каналов нет">
                    Нажмите «Добавить канал» и вставьте ссылку с YouTube.
                </EmptyState>
            ) : (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:gap-4 lg:grid-cols-4 2xl:grid-cols-6">
                    {channels.map((channel) => (
                        <Link
                            key={channel.id}
                            href={`/channels/${channel.id}`}
                            className="group animate-fade-in relative flex flex-col items-center gap-3 overflow-hidden rounded-2xl border border-border/60 bg-card px-4 pt-7 pb-5 text-center transition hover:-translate-y-0.5 hover:shadow-xl hover:shadow-black/5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:hover:shadow-black/40"
                        >
                            {channel.thumbnail && (
                                <img
                                    src={channel.thumbnail}
                                    alt=""
                                    aria-hidden
                                    className="pointer-events-none absolute inset-x-0 top-0 h-24 w-full scale-150 object-cover opacity-25 blur-2xl dark:opacity-30"
                                />
                            )}
                            <ChannelAvatar channel={channel} className="relative size-20 shadow-lg transition-transform duration-300 group-hover:scale-105 md:size-24" />
                            <div className="relative min-w-0">
                                <h2 className="line-clamp-2 font-semibold leading-snug">{channel.name}</h2>
                                <p className="mt-1 text-[13px] text-muted-foreground">
                                    {channelVideoCounts(channel)}
                                    {channel.total_size_bytes ? ` · ${formatBytes(channel.total_size_bytes)}` : ''}
                                </p>
                                {!!channel.queued_count && (
                                    <p className="mt-1 inline-flex items-center gap-1 rounded-full bg-brand/10 px-2 py-0.5 text-[11px] font-medium text-brand">
                                        в очереди {channel.queued_count}
                                    </p>
                                )}
                                {channel.latest_published_at && (
                                    <p className="mt-0.5 text-xs text-muted-foreground/80">
                                        последнее {formatRelative(channel.latest_published_at)}
                                    </p>
                                )}
                            </div>
                            {channel.download_on_demand && (
                                <span className="absolute top-3 left-3 inline-flex items-center gap-1 rounded-full bg-secondary px-2 py-0.5 text-[11px] font-medium text-secondary-foreground">
                                    <Hand className="size-3" />
                                    По запросу
                                </span>
                            )}
                            {channel.is_playlist && (
                                <span className="absolute top-3 right-3 inline-flex items-center gap-1 rounded-full bg-secondary px-2 py-0.5 text-[11px] font-medium text-secondary-foreground">
                                    <ListVideo className="size-3" />
                                    Плейлист
                                </span>
                            )}
                        </Link>
                    ))}
                </div>
            )}
        </>
    );
}
