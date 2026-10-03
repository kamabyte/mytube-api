import { Volume1, Volume2, VolumeX } from 'lucide-react';
import { useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import { ControlButton } from './control-button';

/** Кнопка звука и ползунок громкости, который выезжает при наведении. */
export function VolumeControl({
    volume,
    muted,
    onVolume,
    onToggleMute,
}: {
    volume: number;
    muted: boolean;
    onVolume: (volume: number) => void;
    onToggleMute: () => void;
}) {
    const track = useRef<HTMLDivElement>(null);
    const [dragging, setDragging] = useState(false);
    const level = muted ? 0 : volume;
    const Icon = level === 0 ? VolumeX : level < 0.5 ? Volume1 : Volume2;

    const setFrom = (clientX: number) => {
        const rect = track.current!.getBoundingClientRect();
        onVolume(Math.min(1, Math.max(0, (clientX - rect.left) / rect.width)));
    };

    return (
        <div className="group/volume flex items-center">
            <ControlButton label={level === 0 ? 'Включить звук (m)' : 'Выключить звук (m)'} onClick={onToggleMute}>
                <Icon />
            </ControlButton>
            {/* На тач-экранах громкость — кнопками телефона, ползунок не нужен. */}
            <div
                className={cn(
                    'w-0 overflow-hidden transition-[width] duration-200 pointer-coarse:hidden group-hover/volume:w-20 group-focus-within/volume:w-20',
                    dragging && 'w-20',
                )}
            >
                <div
                    ref={track}
                    role="slider"
                    aria-label="Громкость"
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={Math.round(level * 100)}
                    className="relative mx-2 flex h-8 cursor-pointer touch-none items-center"
                    onPointerDown={(event) => {
                        event.currentTarget.setPointerCapture(event.pointerId);
                        setDragging(true);
                        setFrom(event.clientX);
                    }}
                    onPointerMove={(event) => dragging && setFrom(event.clientX)}
                    onPointerUp={(event) => {
                        event.currentTarget.releasePointerCapture(event.pointerId);
                        setDragging(false);
                    }}
                    onPointerCancel={() => setDragging(false)}
                >
                    <div className="relative h-[3px] w-full rounded-full bg-white/30">
                        <div className="absolute inset-y-0 left-0 rounded-full bg-white" style={{ width: `${level * 100}%` }} />
                        <div
                            className="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white"
                            style={{ left: `${level * 100}%` }}
                        />
                    </div>
                </div>
            </div>
        </div>
    );
}
