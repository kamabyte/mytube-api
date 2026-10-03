import { Head, Link } from '@inertiajs/react';
import { Clapperboard, Clock3, Download, HardDrive, type LucideIcon, Tv } from 'lucide-react';
import { useState } from 'react';
import { PageTitle } from '@/components/empty-state';
import { formatBytes, formatNumber, plural } from '@/lib/format';
import { cn } from '@/lib/utils';

interface Summary {
    total_videos: number;
    total_channels: number;
    total_video_size: number;
    total_duration_seconds: number;
    videos_in_progress: number;
}

interface ChannelRow {
    channel_id: number;
    channel_title: string;
    video_count: number;
    total_size_bytes: number;
}

interface DayRow {
    date: string;
    video_count: number;
    total_size_bytes: number;
}

function Tile({ icon: Icon, label, value, hint }: { icon: LucideIcon; label: string; value: string; hint?: string }) {
    return (
        <div className="rounded-2xl border border-border/60 bg-card p-5">
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Icon className="size-4" />
                {label}
            </div>
            <div className="font-display mt-3 text-3xl font-bold tabular-nums md:text-[34px]">{value}</div>
            {hint && <div className="mt-1 text-xs text-muted-foreground">{hint}</div>}
        </div>
    );
}

const weekday = new Intl.DateTimeFormat('ru-RU', { weekday: 'short' });
const dayMonth = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long' });
const parseDay = (date: string) => new Date(`${date}T00:00:00`);

/** Столбики по дням: одна серия, подсказка при наведении. */
function DailyChart({ days }: { days: DayRow[] }) {
    const [hovered, setHovered] = useState<number | null>(null);
    const max = Math.max(1, ...days.map((day) => day.video_count));
    const total = days.reduce((sum, day) => sum + day.video_count, 0);
    const totalBytes = days.reduce((sum, day) => sum + Number(day.total_size_bytes), 0);
    const active = hovered !== null ? days[hovered] : null;

    return (
        <section className="rounded-2xl border border-border/60 bg-card p-5 md:p-6">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="font-display text-lg font-semibold">Скачано за 14 дней</h2>
                <p className="text-sm text-muted-foreground">
                    {plural(total, ['видео', 'видео', 'видео'])} · {formatBytes(totalBytes)}
                </p>
            </div>

            <div className="relative mt-6">
                {/* Сетка: максимум и середина, еле заметно. */}
                <div className="pointer-events-none absolute inset-x-0 top-0 h-44 md:h-56">
                    {(max >= 2 ? [1, 0.5] : [1]).map((f) => (
                        <div key={f} className="absolute inset-x-0 border-t border-dashed border-border" style={{ bottom: `${f * 100}%` }}>
                            <span className="absolute -top-2.5 right-0 bg-card pl-1.5 text-[11px] text-muted-foreground tabular-nums">
                                {Math.round(max * f)}
                            </span>
                        </div>
                    ))}
                </div>

                {total === 0 && (
                    <div className="absolute inset-x-0 top-0 flex h-44 items-center justify-center text-sm text-muted-foreground md:h-56">
                        За две недели ничего не скачано
                    </div>
                )}

                <div className="relative flex h-44 items-end gap-[2px] pr-8 md:h-56 md:gap-1.5" onMouseLeave={() => setHovered(null)}>
                    {days.map((day, index) => (
                        <button
                            key={day.date}
                            type="button"
                            className="group flex h-full flex-1 items-end focus-visible:outline-none"
                            onMouseEnter={() => setHovered(index)}
                            onFocus={() => setHovered(index)}
                            onBlur={() => setHovered(null)}
                            aria-label={`${dayMonth.format(parseDay(day.date))}: ${plural(day.video_count, ['видео', 'видео', 'видео'])}, ${formatBytes(Number(day.total_size_bytes))}`}
                        >
                            <span
                                className={cn(
                                    'w-full rounded-t-[4px] bg-chart-1 transition-opacity',
                                    hovered !== null && hovered !== index && 'opacity-40',
                                    day.video_count === 0 && 'bg-border',
                                )}
                                style={{ height: day.video_count ? `${(day.video_count / max) * 100}%` : '2px' }}
                            />
                        </button>
                    ))}
                </div>

                {active && hovered !== null && (
                    <div
                        className="pointer-events-none absolute -top-2 z-10 -translate-x-1/2 -translate-y-full rounded-xl border border-border bg-popover px-3 py-2 text-xs whitespace-nowrap shadow-lg"
                        style={{ left: `calc(${((hovered + 0.5) / days.length) * 100}% - ${((hovered + 0.5) / days.length) * 32}px)` }}
                    >
                        <div className="font-semibold">{dayMonth.format(parseDay(active.date))}</div>
                        <div className="text-muted-foreground">
                            {plural(active.video_count, ['видео', 'видео', 'видео'])} · {formatBytes(Number(active.total_size_bytes))}
                        </div>
                    </div>
                )}

                <div className="mt-2 flex gap-[2px] pr-8 text-center text-[10.5px] text-muted-foreground md:gap-1.5">
                    {days.map((day, index) => (
                        <span key={day.date} className={cn('flex-1', index % 2 === 1 && 'max-md:invisible')}>
                            {parseDay(day.date).getDate()}
                            <span className="hidden md:block">{weekday.format(parseDay(day.date))}</span>
                        </span>
                    ))}
                </div>
            </div>
        </section>
    );
}

/** Сколько места занимает каждый канал — горизонтальные полосы с подписями. */
function StorageByChannel({ channels }: { channels: ChannelRow[] }) {
    const max = Math.max(1, ...channels.map((row) => Number(row.total_size_bytes)));

    return (
        <section className="rounded-2xl border border-border/60 bg-card p-5 md:p-6">
            <h2 className="font-display text-lg font-semibold">Место по каналам</h2>
            <ol className="mt-5 flex flex-col gap-4">
                {channels.map((row) => (
                    <li key={row.channel_id}>
                        <div className="flex items-baseline justify-between gap-3 text-sm">
                            <Link href={`/channels/${row.channel_id}`} className="min-w-0 truncate font-medium hover:underline">
                                {row.channel_title}
                            </Link>
                            <span className="shrink-0 text-muted-foreground tabular-nums">
                                {formatBytes(Number(row.total_size_bytes))} · {formatNumber(row.video_count)}
                            </span>
                        </div>
                        <div className="mt-1.5 h-2 rounded-full bg-muted">
                            <div
                                className="h-full rounded-full bg-chart-1"
                                style={{ width: `${Math.max((Number(row.total_size_bytes) / max) * 100, row.total_size_bytes ? 1 : 0)}%` }}
                            />
                        </div>
                    </li>
                ))}
            </ol>
        </section>
    );
}

export default function Stats({ summary, channels, daily }: { summary: Summary; channels: ChannelRow[]; daily: DayRow[] }) {
    return (
        <>
            <Head title="Статистика" />
            <PageTitle>Статистика</PageTitle>

            <div className="grid grid-cols-2 gap-3 md:gap-4 lg:grid-cols-5">
                <Tile icon={Clapperboard} label="Видео" value={formatNumber(summary.total_videos - summary.videos_in_progress)} />
                <Tile icon={Tv} label="Каналы" value={formatNumber(summary.total_channels)} />
                <Tile icon={HardDrive} label="Объём" value={formatBytes(Number(summary.total_video_size))} />
                <Tile
                    icon={Clock3}
                    label="Длительность"
                    value={`${formatNumber(Math.round(summary.total_duration_seconds / 3600))} ч`}
                    hint={`≈ ${formatNumber(Math.round(summary.total_duration_seconds / 86400))} суток подряд`}
                />
                <Tile
                    icon={Download}
                    label="В очереди"
                    value={formatNumber(summary.videos_in_progress)}
                    hint="ждут скачивания"
                />
            </div>

            <div className="mt-6 grid items-start gap-6 xl:grid-cols-[3fr_2fr]">
                <DailyChart days={daily} />
                <StorageByChannel channels={channels} />
            </div>
        </>
    );
}
