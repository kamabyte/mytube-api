import { useEffect, useRef, useState } from 'react';
import { formatDuration } from '@/lib/format';
import { cn } from '@/lib/utils';

/**
 * Полоса прогресса как на YouTube: буфер, подсказка со временем под
 * курсором, перемотка перетаскиванием. Позицию читает из <video> сам,
 * через requestAnimationFrame, — чтобы полоса шла плавно и не
 * перерисовывала весь плеер 60 раз в секунду.
 */
export function ProgressBar({
    media,
    duration,
    onScrubChange,
}: {
    media: HTMLVideoElement | null;
    duration: number;
    onScrubChange: (scrubbing: boolean) => void;
}) {
    const bar = useRef<HTMLDivElement>(null);
    const [played, setPlayed] = useState(0);
    const [buffered, setBuffered] = useState(0);
    const [hover, setHover] = useState<number | null>(null);
    const [scrub, setScrub] = useState<number | null>(null);

    useEffect(() => {
        if (!media) return;
        let frame = 0;
        const tick = () => {
            const total = media.duration || duration;
            if (total > 0) {
                setPlayed(media.currentTime / total);
                const ranges = media.buffered;
                for (let i = 0; i < ranges.length; i++) {
                    if (ranges.start(i) <= media.currentTime && media.currentTime <= ranges.end(i)) {
                        setBuffered(ranges.end(i) / total);
                        break;
                    }
                }
            }
            if (!media.paused) frame = requestAnimationFrame(tick);
        };
        const start = () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(tick);
        };
        start();
        const events = ['play', 'pause', 'seeked', 'seeking', 'progress', 'loadedmetadata', 'emptied', 'timeupdate'];
        events.forEach((name) => media.addEventListener(name, start));
        return () => {
            cancelAnimationFrame(frame);
            events.forEach((name) => media.removeEventListener(name, start));
        };
    }, [media, duration]);

    const fractionAt = (clientX: number) => {
        const rect = bar.current!.getBoundingClientRect();
        return Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
    };

    const seekTo = (fraction: number) => {
        const total = media?.duration || duration;
        if (media && total > 0) media.currentTime = fraction * total;
    };

    const shown = scrub ?? played;
    const tip = scrub ?? hover;

    return (
        <div
            ref={bar}
            role="slider"
            aria-label="Перемотка"
            aria-valuemin={0}
            aria-valuemax={Math.round(duration)}
            aria-valuenow={Math.round(shown * duration)}
            aria-valuetext={formatDuration(shown * duration)}
            className="group/bar relative flex h-4 cursor-pointer touch-none items-center"
            onPointerMove={(event) => {
                const fraction = fractionAt(event.clientX);
                if (event.pointerType === 'mouse') setHover(fraction);
                if (scrub !== null) {
                    setScrub(fraction);
                    seekTo(fraction);
                }
            }}
            onPointerLeave={() => setHover(null)}
            onPointerDown={(event) => {
                if (event.button !== 0) return;
                event.currentTarget.setPointerCapture(event.pointerId);
                const fraction = fractionAt(event.clientX);
                setScrub(fraction);
                seekTo(fraction);
                onScrubChange(true);
            }}
            onPointerUp={(event) => {
                if (scrub === null) return;
                event.currentTarget.releasePointerCapture(event.pointerId);
                seekTo(fractionAt(event.clientX));
                setScrub(null);
                onScrubChange(false);
            }}
            onPointerCancel={() => {
                setScrub(null);
                onScrubChange(false);
            }}
        >
            <div
                className={cn(
                    'relative h-[3px] w-full bg-white/25 transition-[height] duration-100 group-hover/bar:h-[5px]',
                    scrub !== null && 'h-[5px]',
                )}
            >
                <div className="absolute inset-y-0 left-0 bg-white/40" style={{ width: `${buffered * 100}%` }} />
                {hover !== null && scrub === null && (
                    <div className="absolute inset-y-0 left-0 bg-white/50" style={{ width: `${hover * 100}%` }} />
                )}
                <div className="absolute inset-y-0 left-0 bg-brand" style={{ width: `${shown * 100}%` }} />
                <div
                    className={cn(
                        'absolute top-1/2 size-3.5 -translate-x-1/2 -translate-y-1/2 scale-0 rounded-full bg-brand transition-transform duration-100 group-hover/bar:scale-100',
                        scrub !== null && 'scale-100',
                    )}
                    style={{ left: `${shown * 100}%` }}
                />
            </div>

            {tip !== null && duration > 0 && (
                <div
                    className="pointer-events-none absolute bottom-full mb-2 -translate-x-1/2 rounded-md bg-black/80 px-2 py-1 text-xs font-medium text-white tabular-nums"
                    style={{ left: `clamp(1.75rem, ${tip * 100}%, calc(100% - 1.75rem))` }}
                >
                    {formatDuration(tip * duration)}
                </div>
            )}
        </div>
    );
}
