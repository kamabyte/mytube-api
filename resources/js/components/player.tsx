import { router } from '@inertiajs/react';
import {
    Airplay,
    Captions,
    FastForward,
    LoaderCircle,
    Maximize,
    Minimize,
    Pause,
    PictureInPicture2,
    Play,
    RectangleHorizontal,
    Rewind,
    RotateCcw,
    Settings,
    SkipForward,
    Volume2,
    VolumeX,
    X,
} from 'lucide-react';
import { type ReactNode, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { formatDuration } from '@/lib/format';
import { cn } from '@/lib/utils';
import { getProgress, resumePosition, saveProgress } from '@/lib/watch-progress';
import type { SubtitleTrack, Video, VideoDetail } from '@/types';
import { ControlButton } from './player/control-button';
import { ProgressBar } from './player/progress-bar';
import { SettingsMenu, SPEEDS } from './player/settings-menu';
import { VolumeControl } from './player/volume-control';

const COUNTDOWN = 6;
const SAVE_EVERY_MS = 4000;
const HIDE_CONTROLS_MS = 2500;
const DOUBLE_TAP_MS = 300;
const PREFS_KEY = 'mytube:player:v1';

export interface PlayerHandle {
    seek: (seconds: number) => void;
}

interface Prefs {
    volume: number;
    muted: boolean;
    rate: number;
    subtitles: boolean;
    /** Последний выбранный язык субтитров — его и включаем в следующих видео. */
    subtitleLanguage: string | null;
}

const DEFAULT_PREFS: Prefs = { volume: 1, muted: false, rate: 1, subtitles: false, subtitleLanguage: null };

interface Bezel {
    id: number;
    icon: ReactNode;
    text?: string;
    side: 'left' | 'center' | 'right';
}

/** Safari-only API: AirPlay и полноэкранный режим iPhone. */
type WebkitVideo = HTMLVideoElement & {
    webkitShowPlaybackTargetPicker?: () => void;
    webkitEnterFullscreen?: () => void;
};

function readPrefs(): Prefs {
    try {
        return { ...DEFAULT_PREFS, ...JSON.parse(localStorage.getItem(PREFS_KEY) ?? '{}') };
    } catch {
        return DEFAULT_PREFS;
    }
}

function writePrefs(prefs: Prefs) {
    try {
        localStorage.setItem(PREFS_KEY, JSON.stringify(prefs));
    } catch {
        // только на эту сессию
    }
}

/** Дорожка по запомненному языку, иначе русская, иначе первая. */
function pickSubtitle(tracks: SubtitleTrack[], language: string | null): number {
    const byLanguage = (codes: (string | null)[]) => tracks.findIndex((track) => codes.includes(track.language));
    const index = language ? byLanguage([language]) : -1;
    if (index !== -1) return index;
    const russian = byLanguage(['rus', 'ru']);
    return russian !== -1 ? russian : 0;
}

function parseTimestamp(value: string): number {
    return value.split(':').reduce((total, part) => total * 60 + Number(part), 0);
}

/** WebVTT от ffmpeg: блоки «начало --> конец» и текст до пустой строки. */
function parseVtt(text: string): VTTCue[] {
    return text
        .replace(/\r/g, '')
        .split(/\n{2,}/)
        .flatMap((block) => {
            const lines = block.split('\n');
            const at = lines.findIndex((line) => line.includes('-->'));
            if (at === -1) return [];
            const [start, end] = lines[at].split('-->').map((part) => parseTimestamp(part.trim().split(/\s+/)[0]));
            const body = lines.slice(at + 1).join('\n').trim();
            return Number.isFinite(start) && Number.isFinite(end) && body ? [new VTTCue(start, end, body)] : [];
        });
}

/**
 * Загруженные дорожки по элементу и адресу: addTextTrack дорожку не удалить,
 * поэтому при повторном включении берём уже созданную.
 */
const loadedTracks = new WeakMap<HTMLVideoElement, Map<string, TextTrack>>();

function isTyping(target: EventTarget | null) {
    const el = target as HTMLElement | null;
    return !!el && (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) || el.isContentEditable);
}

/**
 * Плеер в духе YouTube: свои контролы поверх <video> — полоса с буфером и
 * подсказкой времени, громкость, скорость, картинка-в-картинке, AirPlay,
 * полный экран, двойной тап для перемотки на телефоне. Плюс продолжение
 * с места, автопереход к следующему видео и горячие клавиши YouTube.
 */
export function Player({
    video,
    next,
    autoplayNext,
    onAutoplayNextChange,
    theater = false,
    onTheaterChange,
    onReady,
}: {
    video: VideoDetail;
    next?: Video;
    autoplayNext: boolean;
    onAutoplayNextChange?: (value: boolean) => void;
    /** Режим кинотеатра: плеер во всю ширину страницы, раскладкой управляет страница. */
    theater?: boolean;
    onTheaterChange?: (value: boolean) => void;
    onReady?: (handle: PlayerHandle) => void;
}) {
    const container = useRef<HTMLDivElement>(null);
    const ref = useRef<HTMLVideoElement | null>(null);
    const [media, setMedia] = useState<HTMLVideoElement | null>(null);
    const lastSave = useRef(0);
    const pointerType = useRef('mouse');
    const lastTap = useRef<{ time: number; side: Bezel['side'] } | null>(null);
    const hideTimer = useRef(0);
    const bezelTimer = useRef(0);

    const [resumedAt, setResumedAt] = useState<number | null>(null);
    const [countdown, setCountdown] = useState<number | null>(null);
    const [failed, setFailed] = useState(false);

    const [paused, setPaused] = useState(true);
    const [ended, setEnded] = useState(false);
    const [started, setStarted] = useState(false);
    const [waiting, setWaiting] = useState(false);
    const [currentTime, setCurrentTime] = useState(0);
    const [duration, setDuration] = useState(video.duration_seconds);
    const [prefs, setPrefs] = useState(readPrefs);
    const [fullscreen, setFullscreen] = useState(false);
    const [pip, setPip] = useState(false);
    const [airplay, setAirplay] = useState(false);

    const [active, setActive] = useState(true);
    const [overControls, setOverControls] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [scrubbing, setScrubbing] = useState(false);
    const [bezel, setBezel] = useState<Bezel | null>(null);
    const [cue, setCue] = useState('');

    const updatePrefs = useCallback((patch: Partial<Prefs>) => {
        setPrefs((current) => {
            const updated = { ...current, ...patch };
            writePrefs(updated);
            return updated;
        });
    }, []);

    const subtitles = video.subtitles;
    const subtitle = useMemo(
        () => (prefs.subtitles && subtitles.length > 0 ? pickSubtitle(subtitles, prefs.subtitleLanguage) : null),
        [prefs.subtitles, prefs.subtitleLanguage, subtitles],
    );

    const showControls = active || paused || overControls || settingsOpen || scrubbing;

    const attach = useCallback((el: HTMLVideoElement | null) => {
        ref.current = el;
        setMedia(el);
    }, []);

    const persist = useCallback(() => {
        const el = ref.current;
        if (el && el.currentTime > 0) {
            saveProgress(video, el.currentTime, el.duration || video.duration_seconds);
            lastSave.current = Date.now();
        }
    }, [video]);

    // Смена видео: сбросить оверлеи, а при уходе сохранить позицию.
    // Элемент берём заранее: к моменту очистки ref уже указывает на
    // <video> следующего ролика.
    useEffect(() => {
        setCountdown(null);
        setResumedAt(null);
        setFailed(false);
        setPaused(true);
        setEnded(false);
        setStarted(false);
        setCurrentTime(0);
        setDuration(video.duration_seconds);

        const el = ref.current;
        const save = () => {
            if (el && el.currentTime > 0) {
                saveProgress(video, el.currentTime, el.duration || video.duration_seconds);
            }
        };
        window.addEventListener('pagehide', save);

        return () => {
            window.removeEventListener('pagehide', save);
            save();
        };
    }, [video]);

    // Громкость и скорость переживают смену видео и перезагрузку.
    useEffect(() => {
        if (!media) return;
        const saved = readPrefs();
        media.volume = saved.volume;
        media.muted = saved.muted;
        media.defaultPlaybackRate = saved.rate;
        media.playbackRate = saved.rate;

        const onPip = () => setPip(document.pictureInPictureElement === media);
        const onAirplay = (event: Event) => setAirplay((event as Event & { availability: string }).availability === 'available');
        media.addEventListener('enterpictureinpicture', onPip);
        media.addEventListener('leavepictureinpicture', onPip);
        media.addEventListener('webkitplaybacktargetavailabilitychanged', onAirplay);
        return () => {
            media.removeEventListener('enterpictureinpicture', onPip);
            media.removeEventListener('leavepictureinpicture', onPip);
            media.removeEventListener('webkitplaybacktargetavailabilitychanged', onAirplay);
        };
    }, [media]);

    // Субтитры рисуем сами (дорожка в режиме hidden): так они поднимаются
    // над панелью управления, а не прячутся под ней.
    // VTT грузим сами, а не через <track>: пока такая дорожка грузится, браузер
    // держит <video> и автозапуск ждёт, а первое извлечение — это ffmpeg по
    // всему файлу. Дорожки из addTextTrack воспроизведение не задерживают.
    useEffect(() => {
        setCue('');
        if (!media) return;
        const source = subtitle === null ? undefined : subtitles[subtitle];
        let track: TextTrack | undefined;
        const controller = new AbortController();

        const update = () => {
            const cues = Array.from(track?.activeCues ?? []) as VTTCue[];
            setCue(cues.map((item) => item.getCueAsHTML().textContent ?? '').join('\n'));
        };
        // Safari видит и вшитые mov_text-дорожки — их тоже глушим.
        const show = (selected: TextTrack | undefined) => {
            Array.from(media.textTracks).forEach((item) => {
                item.mode = item === selected ? 'hidden' : 'disabled';
            });
            track = selected;
            if (!selected) return;
            selected.addEventListener('cuechange', update);
            update();
        };

        const loaded = loadedTracks.get(media) ?? new Map<string, TextTrack>();
        loadedTracks.set(media, loaded);

        if (!source) show(undefined);
        else if (loaded.has(source.url)) show(loaded.get(source.url));
        else {
            show(undefined);
            fetch(source.url, { signal: controller.signal })
                .then((response) => (response.ok ? response.text() : Promise.reject(new Error(response.statusText))))
                .then((text) => {
                    const created = media.addTextTrack('subtitles', source.label, source.language ?? '');
                    parseVtt(text).forEach((item) => created.addCue(item));
                    loaded.set(source.url, created);
                    show(created);
                })
                .catch(() => {});
        }

        return () => {
            controller.abort();
            track?.removeEventListener('cuechange', update);
        };
    }, [media, subtitle, subtitles]);

    useEffect(() => {
        const onChange = () => setFullscreen(!!container.current && document.fullscreenElement === container.current);
        document.addEventListener('fullscreenchange', onChange);
        return () => document.removeEventListener('fullscreenchange', onChange);
    }, []);

    useEffect(
        () => () => {
            window.clearTimeout(hideTimer.current);
            window.clearTimeout(bezelTimer.current);
        },
        [],
    );

    /** Показать контролы и спрятать их снова, если пользователь затих. */
    const poke = useCallback(() => {
        setActive(true);
        window.clearTimeout(hideTimer.current);
        hideTimer.current = window.setTimeout(() => setActive(false), HIDE_CONTROLS_MS);
    }, []);

    const flash = useCallback((icon: ReactNode, text?: string, side: Bezel['side'] = 'center') => {
        setBezel({ id: Date.now(), icon, text, side });
        window.clearTimeout(bezelTimer.current);
        bezelTimer.current = window.setTimeout(() => setBezel(null), 650);
    }, []);

    const selectSubtitle = useCallback(
        (index: number | null) => {
            if (index === null) updatePrefs({ subtitles: false });
            else updatePrefs({ subtitles: true, subtitleLanguage: subtitles[index]?.language ?? null });
        },
        [subtitles, updatePrefs],
    );

    const toggleSubtitles = useCallback(() => {
        if (subtitles.length === 0) return;
        const enable = subtitle === null;
        updatePrefs({ subtitles: enable });
        flash(<Captions />, enable ? 'Вкл.' : 'Выкл.');
    }, [subtitles, subtitle, updatePrefs, flash]);

    const togglePlay = useCallback(
        (withBezel = true) => {
            const el = ref.current;
            if (!el) return;
            if (el.paused || el.ended) {
                if (el.ended) el.currentTime = 0;
                void el.play().catch(() => {});
                if (withBezel) flash(<Play className="fill-current" />);
            } else {
                el.pause();
                if (withBezel) flash(<Pause className="fill-current" />);
            }
        },
        [flash],
    );

    const seekBy = useCallback(
        (delta: number) => {
            const el = ref.current;
            if (!el) return;
            el.currentTime = Math.min(el.duration || Infinity, Math.max(0, el.currentTime + delta));
            const Icon = delta < 0 ? Rewind : FastForward;
            flash(<Icon className="fill-current" />, `${Math.abs(delta)} с`, delta < 0 ? 'left' : 'right');
        },
        [flash],
    );

    const setVolume = useCallback((volume: number) => {
        const el = ref.current;
        if (!el) return;
        el.volume = volume;
        el.muted = volume === 0;
    }, []);

    const changeVolume = useCallback(
        (delta: number) => {
            const el = ref.current;
            if (!el) return;
            const volume = Math.round(Math.min(1, Math.max(0, (el.muted ? 0 : el.volume) + delta)) * 100) / 100;
            setVolume(volume);
            flash(volume === 0 ? <VolumeX /> : <Volume2 />, `${Math.round(volume * 100)}%`);
        },
        [flash, setVolume],
    );

    const toggleMute = useCallback(() => {
        const el = ref.current;
        if (!el) return;
        if (el.muted || el.volume === 0) {
            el.muted = false;
            if (el.volume === 0) el.volume = 0.5;
        } else {
            el.muted = true;
        }
    }, []);

    const setRate = useCallback((rate: number) => {
        const el = ref.current;
        if (!el) return;
        el.defaultPlaybackRate = rate;
        el.playbackRate = rate;
    }, []);

    const stepRate = useCallback(
        (direction: 1 | -1) => {
            const el = ref.current;
            if (!el) return;
            const index = SPEEDS.indexOf(el.playbackRate);
            const nextRate = SPEEDS[Math.min(SPEEDS.length - 1, Math.max(0, (index === -1 ? 3 : index) + direction))];
            setRate(nextRate);
            flash(<span className="text-lg font-semibold">{nextRate}×</span>);
        },
        [flash, setRate],
    );

    const toggleFullscreen = useCallback(() => {
        const box = container.current;
        const el = ref.current as WebkitVideo | null;
        if (document.fullscreenElement) void document.exitFullscreen();
        else if (box?.requestFullscreen) void box.requestFullscreen().catch(() => {});
        else el?.webkitEnterFullscreen?.(); // iPhone: только нативный полноэкранный режим
    }, []);

    const togglePip = useCallback(() => {
        const el = ref.current;
        if (!el || !document.pictureInPictureEnabled) return;
        if (document.pictureInPictureElement) void document.exitPictureInPicture();
        else void el.requestPictureInPicture().catch(() => {});
    }, []);

    useEffect(() => {
        onReady?.({
            seek: (seconds) => {
                const el = ref.current;
                if (!el) return;
                el.currentTime = seconds;
                void el.play().catch(() => {});
                container.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            },
        });
    }, [onReady]);

    const goNext = useCallback(() => {
        if (next) router.visit(`/watch/${next.id}`);
    }, [next]);

    // Обратный отсчёт до следующего видео.
    useEffect(() => {
        if (countdown === null) return;
        if (countdown <= 0) {
            goNext();
            return;
        }
        const timer = window.setTimeout(() => setCountdown((c) => (c === null ? null : c - 1)), 1000);
        return () => window.clearTimeout(timer);
    }, [countdown, goNext]);

    // Media Session: название на экране блокировки, «следующий трек» с клавиатуры.
    useEffect(() => {
        if (!('mediaSession' in navigator)) return;
        navigator.mediaSession.metadata = new MediaMetadata({
            title: video.name,
            artist: video.channel?.name ?? 'MyTube',
            artwork: video.thumbnail ? [{ src: new URL(video.thumbnail, window.location.origin).href }] : [],
        });
        navigator.mediaSession.setActionHandler('nexttrack', next ? goNext : null);
        return () => navigator.mediaSession.setActionHandler('nexttrack', null);
    }, [video, next, goNext]);

    // Горячие клавиши как на YouTube. По event.code — чтобы работали и в
    // русской раскладке. Стрелки вверх/вниз — только когда фокус в плеере,
    // иначе они нужны для прокрутки страницы.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const el = ref.current;
            if (!el || isTyping(event.target) || event.metaKey || event.ctrlKey || event.altKey) return;
            const focused = !!container.current?.contains(document.activeElement);

            if (event.code.startsWith('Digit') && !event.shiftKey) {
                const total = el.duration || video.duration_seconds;
                el.currentTime = (Number(event.code.slice(5)) / 10) * total;
                return;
            }

            switch (event.code) {
                case 'Space':
                case 'KeyK':
                    event.preventDefault();
                    togglePlay();
                    break;
                case 'KeyJ':
                    seekBy(-10);
                    break;
                case 'KeyL':
                    seekBy(10);
                    break;
                case 'ArrowLeft':
                    event.preventDefault();
                    seekBy(-5);
                    break;
                case 'ArrowRight':
                    event.preventDefault();
                    seekBy(5);
                    break;
                case 'ArrowUp':
                case 'ArrowDown':
                    if (!focused) return;
                    event.preventDefault();
                    changeVolume(event.code === 'ArrowUp' ? 0.05 : -0.05);
                    break;
                case 'KeyF':
                    toggleFullscreen();
                    break;
                case 'KeyM':
                    toggleMute();
                    flash(el.muted ? <VolumeX /> : <Volume2 />);
                    break;
                case 'KeyI':
                    togglePip();
                    break;
                case 'KeyC':
                    if (subtitles.length === 0) return;
                    toggleSubtitles();
                    break;
                case 'KeyT':
                    // Как и кнопка, только на широком экране (lg).
                    if (!onTheaterChange || !window.matchMedia('(min-width: 64rem)').matches) return;
                    onTheaterChange(!theater);
                    break;
                case 'Comma':
                case 'Period':
                    if (event.shiftKey) stepRate(event.code === 'Period' ? 1 : -1);
                    break;
                case 'KeyN':
                    if (event.shiftKey) goNext();
                    break;
                default:
                    return;
            }
            poke();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [video, goNext, togglePlay, seekBy, changeVolume, toggleFullscreen, toggleMute, togglePip, stepRate, flash, poke, theater, onTheaterChange, subtitles, toggleSubtitles]);

    /** Тап на телефоне: показать/спрятать контролы; двойной тап по краю — перемотка. */
    const onTap = (clientX: number) => {
        const rect = ref.current!.getBoundingClientRect();
        const x = (clientX - rect.left) / rect.width;
        const side: Bezel['side'] = x < 0.35 ? 'left' : x > 0.65 ? 'right' : 'center';
        const now = Date.now();
        const last = lastTap.current;
        lastTap.current = { time: now, side };

        if (last && now - last.time < DOUBLE_TAP_MS && last.side === side && side !== 'center') {
            seekBy(side === 'left' ? -10 : 10);
            return;
        }
        if (showControls && !paused) {
            window.clearTimeout(hideTimer.current);
            setActive(false);
        } else {
            poke();
        }
    };

    const canPip = typeof document !== 'undefined' && document.pictureInPictureEnabled;

    return (
        <div
            ref={container}
            tabIndex={-1}
            className={cn(
                'group/player @container relative overflow-hidden bg-black shadow-2xl shadow-black/20 outline-none select-none dark:shadow-black/60',
                fullscreen ? 'flex items-center' : theater ? 'sm:rounded-2xl lg:rounded-none lg:shadow-none' : 'sm:rounded-2xl',
                !showControls && 'cursor-none',
            )}
            onPointerMove={(event) => {
                if (event.pointerType === 'mouse') poke();
            }}
            onPointerLeave={(event) => {
                if (event.pointerType === 'mouse' && !paused) {
                    window.clearTimeout(hideTimer.current);
                    setActive(false);
                }
            }}
        >
            <video
                key={video.id}
                ref={attach}
                src={video.stream_url}
                poster={video.thumbnail ?? undefined}
                autoPlay
                playsInline
                preload="metadata"
                className={cn('w-full bg-black', fullscreen ? 'h-full object-contain' : 'aspect-video', theater && !fullscreen && 'lg:max-h-[calc(100svh-10rem)] lg:object-contain')}
                onPointerDown={(event) => {
                    pointerType.current = event.pointerType;
                }}
                onClick={(event) => {
                    container.current?.focus({ preventScroll: true });
                    if (settingsOpen) return;
                    if (pointerType.current === 'mouse') togglePlay();
                    else onTap(event.clientX);
                }}
                onDoubleClick={() => {
                    if (pointerType.current === 'mouse') toggleFullscreen();
                }}
                onLoadedMetadata={(event) => {
                    const el = event.currentTarget;
                    setDuration(el.duration || video.duration_seconds);
                    const position = resumePosition(getProgress(video.id));
                    if (position > 0) {
                        el.currentTime = position;
                        setResumedAt(position);
                        window.setTimeout(() => setResumedAt(null), 6000);
                    }
                }}
                onDurationChange={(event) => setDuration(event.currentTarget.duration || video.duration_seconds)}
                onTimeUpdate={(event) => {
                    setCurrentTime(event.currentTarget.currentTime);
                    if (Date.now() - lastSave.current > SAVE_EVERY_MS) persist();
                }}
                onPause={() => {
                    setPaused(true);
                    persist();
                }}
                onPlay={() => {
                    setPaused(false);
                    setEnded(false);
                    setStarted(true);
                    setCountdown(null);
                    poke();
                }}
                onWaiting={() => setWaiting(true)}
                onPlaying={() => setWaiting(false)}
                onCanPlay={() => setWaiting(false)}
                onSeeked={() => setWaiting(false)}
                onVolumeChange={(event) => updatePrefs({ volume: event.currentTarget.volume, muted: event.currentTarget.muted })}
                onRateChange={(event) => updatePrefs({ rate: event.currentTarget.playbackRate })}
                onEnded={(event) => {
                    const el = event.currentTarget;
                    setEnded(true);
                    saveProgress(video, el.duration, el.duration);
                    if (autoplayNext && next) setCountdown(COUNTDOWN);
                }}
                onError={() => setFailed(true)}
            />

            {cue && (
                <div
                    className={cn(
                        'pointer-events-none absolute inset-x-0 z-[5] flex justify-center px-[8%] text-center transition-[bottom] duration-200',
                        showControls && countdown === null ? 'bottom-[4.5rem]' : 'bottom-[6%]',
                    )}
                >
                    <p className="text-[clamp(13px,3.2cqw,34px)] leading-snug whitespace-pre-line text-white">
                        <span className="rounded-sm bg-black/75 px-[0.35em] py-[0.05em] box-decoration-clone">{cue}</span>
                    </p>
                </div>
            )}

            {/* Иконка действия по центру или у края — как «пузырь» на YouTube. */}
            {bezel && (
                <div
                    className={cn(
                        'pointer-events-none absolute inset-y-0 flex items-center justify-center',
                        bezel.side === 'left' && 'left-0 w-1/3',
                        bezel.side === 'right' && 'right-0 w-1/3',
                        bezel.side === 'center' && 'inset-x-0',
                    )}
                >
                    <div
                        key={bezel.id}
                        className="animate-bezel flex size-16 flex-col items-center justify-center gap-0.5 rounded-full bg-black/60 text-white [&_svg]:size-7"
                    >
                        {bezel.icon}
                        {bezel.text && <span className="text-[11px] font-medium tabular-nums">{bezel.text}</span>}
                    </div>
                </div>
            )}

            {waiting && !paused && (
                <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <LoaderCircle className="size-12 animate-spin text-white/90 [animation-delay:150ms]" />
                </div>
            )}

            {/* Большая кнопка: до первого запуска (автозапуск заблокирован) и на тач-экранах. */}
            {!failed && countdown === null && (!started || (showControls && !waiting)) && (
                <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <button
                        type="button"
                        aria-label={paused ? 'Смотреть' : 'Пауза'}
                        onClick={() => togglePlay(false)}
                        className={cn(
                            'pointer-events-auto inline-flex size-16 items-center justify-center rounded-full bg-black/55 text-white backdrop-blur-sm transition hover:bg-black/70 [&_svg]:size-8',
                            started && 'pointer-fine:hidden',
                        )}
                    >
                        {ended ? <RotateCcw /> : paused ? <Play className="ml-1 fill-current" /> : <Pause className="fill-current" />}
                    </button>
                </div>
            )}

            {fullscreen && (
                <div
                    className={cn(
                        'pointer-events-none absolute inset-x-0 top-0 bg-linear-to-b from-black/80 to-transparent px-6 pt-5 pb-16 text-lg font-semibold text-white transition-opacity duration-200',
                        showControls ? 'opacity-100' : 'opacity-0',
                    )}
                >
                    {video.name}
                </div>
            )}

            {/* Панель управления. */}
            <div
                className={cn(
                    'absolute inset-x-0 bottom-0 z-10 transition-opacity duration-200',
                    showControls && countdown === null ? 'opacity-100' : 'pointer-events-none opacity-0',
                )}
                onPointerEnter={(event) => event.pointerType === 'mouse' && setOverControls(true)}
                onPointerLeave={() => setOverControls(false)}
            >
                <div className="pointer-events-none absolute inset-x-0 bottom-0 h-28 bg-linear-to-t from-black/80 to-transparent" />
                <div className="relative px-2 sm:px-3">
                    <div className="px-1">
                        <ProgressBar media={media} duration={duration} onScrubChange={setScrubbing} />
                    </div>
                    <div className="flex items-center pb-1 text-white">
                        <ControlButton label={paused ? 'Смотреть (k)' : 'Пауза (k)'} onClick={() => togglePlay(false)}>
                            {ended ? <RotateCcw /> : paused ? <Play className="fill-current" /> : <Pause className="fill-current" />}
                        </ControlButton>
                        {next && (
                            <ControlButton label="Следующее видео (Shift+N)" onClick={goNext}>
                                <SkipForward className="fill-current" />
                            </ControlButton>
                        )}
                        <VolumeControl volume={prefs.volume} muted={prefs.muted} onVolume={setVolume} onToggleMute={toggleMute} />
                        <div className="ml-1 text-[13px] whitespace-nowrap text-white/90 tabular-nums">
                            {formatDuration(currentTime)}
                            <span className="text-white/60"> / {formatDuration(duration)}</span>
                        </div>

                        <div className="ml-auto flex items-center">
                            {subtitles.length > 0 && (
                                <ControlButton
                                    label={subtitle === null ? 'Включить субтитры (c)' : 'Выключить субтитры (c)'}
                                    aria-pressed={subtitle !== null}
                                    onClick={toggleSubtitles}
                                    className={cn(
                                        'relative after:absolute after:inset-x-2.5 after:bottom-1.5 after:h-[3px] after:rounded-full after:bg-brand after:transition-transform',
                                        subtitle === null && 'after:scale-x-0',
                                    )}
                                >
                                    <Captions />
                                </ControlButton>
                            )}
                            <ControlButton
                                label="Настройки"
                                data-settings-toggle
                                aria-expanded={settingsOpen}
                                onClick={() => setSettingsOpen((open) => !open)}
                                className={cn('[&_svg]:transition-transform', settingsOpen && '[&_svg]:rotate-45')}
                            >
                                <Settings />
                            </ControlButton>
                            {canPip && (
                                <ControlButton
                                    label={pip ? 'Выйти из режима «картинка в картинке» (i)' : 'Картинка в картинке (i)'}
                                    onClick={togglePip}
                                    className="max-sm:hidden"
                                >
                                    <PictureInPicture2 />
                                </ControlButton>
                            )}
                            {airplay && (
                                <ControlButton label="AirPlay" onClick={() => (ref.current as WebkitVideo | null)?.webkitShowPlaybackTargetPicker?.()}>
                                    <Airplay />
                                </ControlButton>
                            )}
                            {onTheaterChange && !fullscreen && (
                                <ControlButton
                                    label={theater ? 'Обычный режим (t)' : 'Режим кинотеатра (t)'}
                                    aria-pressed={theater}
                                    onClick={() => onTheaterChange(!theater)}
                                    className="max-lg:hidden"
                                >
                                    <RectangleHorizontal className={cn('transition-transform', !theater && 'scale-x-125')} />
                                </ControlButton>
                            )}
                            <ControlButton label={fullscreen ? 'Выйти из полноэкранного режима (f)' : 'Во весь экран (f)'} onClick={toggleFullscreen}>
                                {fullscreen ? <Minimize /> : <Maximize />}
                            </ControlButton>
                        </div>
                    </div>
                </div>
            </div>

            {settingsOpen && (
                <SettingsMenu
                    rate={prefs.rate}
                    onRate={setRate}
                    autoplayNext={autoplayNext}
                    onAutoplayNext={onAutoplayNextChange}
                    subtitles={subtitles.map((track) => track.label)}
                    subtitle={subtitle}
                    onSubtitle={selectSubtitle}
                    onClose={() => setSettingsOpen(false)}
                />
            )}

            {resumedAt !== null && (
                <div className="animate-fade-in absolute top-3 left-3 z-10 flex items-center gap-1 rounded-full bg-black/70 py-1 pr-1 pl-3 text-[13px] text-white backdrop-blur-md">
                    Продолжено с {formatDuration(resumedAt)}
                    <button
                        onClick={() => {
                            if (ref.current) ref.current.currentTime = 0;
                            setResumedAt(null);
                        }}
                        className="ml-1 inline-flex items-center gap-1 rounded-full bg-white/15 px-2.5 py-1 font-medium hover:bg-white/25"
                    >
                        <RotateCcw className="size-3" />С начала
                    </button>
                </div>
            )}

            {failed && (
                <div className="absolute inset-0 z-20 flex flex-col items-center justify-center gap-2 bg-black/80 p-6 text-center text-white">
                    <p className="font-semibold">Не удалось воспроизвести видео</p>
                    <p className="max-w-sm text-sm text-white/60">Файл недоступен на сервере или формат не поддерживается этим браузером.</p>
                </div>
            )}

            {countdown !== null && next && (
                <div className="animate-fade-in absolute inset-0 z-20 flex items-center justify-center bg-black/75 p-4 backdrop-blur-sm">
                    <div className="flex w-full max-w-md flex-col items-center gap-4 text-center text-white">
                        <p className="text-sm text-white/70">Следующее видео через {countdown}</p>
                        <div className="flex w-full items-center gap-3 text-left">
                            {next.thumbnail && <img src={next.thumbnail} alt="" className="aspect-video w-32 rounded-lg object-cover" />}
                            <p className="line-clamp-3 font-semibold">{next.name}</p>
                        </div>
                        <div className="flex gap-2">
                            <button
                                onClick={() => setCountdown(null)}
                                className="inline-flex h-10 items-center gap-2 rounded-full bg-white/15 px-5 text-sm font-semibold hover:bg-white/25"
                            >
                                <X className="size-4" />
                                Отмена
                            </button>
                            <button
                                onClick={goNext}
                                className="inline-flex h-10 items-center gap-2 rounded-full bg-white px-5 text-sm font-semibold text-black hover:bg-white/90"
                            >
                                <SkipForward className="size-4 fill-current" />
                                Смотреть
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
