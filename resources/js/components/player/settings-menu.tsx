import { Captions, Check, ChevronLeft, ChevronRight, Gauge, ListVideo } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export const SPEEDS = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];

const speedLabel = (rate: number) => (rate === 1 ? 'Обычная' : `${rate}×`);

/**
 * Панель настроек внутри плеера. Не Radix-меню: его портал рендерится в
 * <body> и в полноэкранном режиме оказывается за пределами видимого.
 */
export function SettingsMenu({
    rate,
    onRate,
    autoplayNext,
    onAutoplayNext,
    subtitles,
    subtitle,
    onSubtitle,
    onClose,
}: {
    rate: number;
    onRate: (rate: number) => void;
    autoplayNext: boolean;
    onAutoplayNext?: (value: boolean) => void;
    /** Подписи дорожек; выбранная — индекс в этом списке, null — выключены. */
    subtitles: string[];
    subtitle: number | null;
    onSubtitle: (index: number | null) => void;
    onClose: () => void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [page, setPage] = useState<'main' | 'speed' | 'subtitles'>('main');

    useEffect(() => {
        const onDown = (event: PointerEvent) => {
            const target = event.target as HTMLElement;
            if (!ref.current?.contains(target) && !target.closest('[data-settings-toggle]')) onClose();
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                onClose();
            }
        };
        document.addEventListener('pointerdown', onDown);
        document.addEventListener('keydown', onKey, true);
        return () => {
            document.removeEventListener('pointerdown', onDown);
            document.removeEventListener('keydown', onKey, true);
        };
    }, [onClose]);

    const row = 'flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-white/10';

    return (
        <div
            ref={ref}
            className="animate-fade-in absolute right-3 bottom-16 z-20 w-64 max-w-[calc(100%-1.5rem)] overflow-hidden rounded-xl bg-black/85 py-2 text-white shadow-xl backdrop-blur-md"
            onClick={(event) => event.stopPropagation()}
        >
            {page === 'main' ? (
                <>
                    <button type="button" className={row} onClick={() => setPage('speed')}>
                        <Gauge className="size-5" />
                        <span className="flex-1">Скорость</span>
                        <span className="text-white/70">{speedLabel(rate)}</span>
                        <ChevronRight className="size-4 text-white/70" />
                    </button>
                    {subtitles.length > 0 && (
                        <button type="button" className={row} onClick={() => setPage('subtitles')}>
                            <Captions className="size-5" />
                            <span className="flex-1">Субтитры</span>
                            <span className="max-w-24 truncate text-white/70">{subtitle === null ? 'Выкл.' : subtitles[subtitle]}</span>
                            <ChevronRight className="size-4 shrink-0 text-white/70" />
                        </button>
                    )}
                    {onAutoplayNext && (
                        <button
                            type="button"
                            role="switch"
                            aria-checked={autoplayNext}
                            className={row}
                            onClick={() => onAutoplayNext(!autoplayNext)}
                        >
                            <ListVideo className="size-5" />
                            <span className="flex-1">Автовоспроизведение</span>
                            <span
                                className={cn(
                                    'relative h-5 w-9 rounded-full transition-colors',
                                    autoplayNext ? 'bg-brand' : 'bg-white/30',
                                )}
                            >
                                <span
                                    className={cn(
                                        'absolute top-0.5 left-0.5 size-4 rounded-full bg-white transition-transform',
                                        autoplayNext && 'translate-x-4',
                                    )}
                                />
                            </span>
                        </button>
                    )}
                </>
            ) : page === 'subtitles' ? (
                <>
                    <button type="button" className={cn(row, 'border-b border-white/15 pb-3 font-semibold')} onClick={() => setPage('main')}>
                        <ChevronLeft className="size-5" />
                        Субтитры
                    </button>
                    <div className="max-h-64 overflow-y-auto pt-1">
                        {[null, ...subtitles.map((_, index) => index)].map((index) => (
                            <button
                                key={index ?? 'off'}
                                type="button"
                                className={row}
                                onClick={() => {
                                    onSubtitle(index);
                                    onClose();
                                }}
                            >
                                <Check className={cn('size-4 shrink-0', index !== subtitle && 'invisible')} />
                                {index === null ? 'Выкл.' : subtitles[index]}
                            </button>
                        ))}
                    </div>
                </>
            ) : (
                <>
                    <button type="button" className={cn(row, 'border-b border-white/15 pb-3 font-semibold')} onClick={() => setPage('main')}>
                        <ChevronLeft className="size-5" />
                        Скорость
                    </button>
                    <div className="max-h-64 overflow-y-auto pt-1">
                        {SPEEDS.map((speed) => (
                            <button
                                key={speed}
                                type="button"
                                className={row}
                                onClick={() => {
                                    onRate(speed);
                                    onClose();
                                }}
                            >
                                <Check className={cn('size-4', speed !== rate && 'invisible')} />
                                {speedLabel(speed)}
                            </button>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}
