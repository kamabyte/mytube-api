const numberFormat = new Intl.NumberFormat('ru-RU');
const compactFormat = new Intl.NumberFormat('ru-RU', { notation: 'compact', maximumFractionDigits: 1 });
const relativeFormat = new Intl.RelativeTimeFormat('ru-RU', { numeric: 'auto' });
const dateFormat = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
const pluralRules = new Intl.PluralRules('ru-RU');

/** plural(5, ['видео', 'видео', 'видео']) — формы: one, few, many. */
export function plural(count: number, forms: [string, string, string]): string {
    const rule = pluralRules.select(count);
    const form = rule === 'one' ? forms[0] : rule === 'few' ? forms[1] : forms[2];

    return `${numberFormat.format(count)} ${form}`;
}

export function formatNumber(value: number): string {
    return numberFormat.format(value);
}

/** 3725 → «1:02:05», 65 → «1:05». */
export function formatDuration(seconds: number): string {
    const total = Math.max(0, Math.round(seconds));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const pad = (n: number) => String(n).padStart(2, '0');

    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

/** Длительность словами для сводок: «518 ч», «42 мин». */
export function formatHours(seconds: number): string {
    const hours = seconds / 3600;

    if (hours >= 1) {
        return `${numberFormat.format(Math.round(hours))} ч`;
    }

    return `${Math.round(seconds / 60)} мин`;
}

export function formatViews(count: number): string {
    // «34 тыс. просмотров»: после сокращения согласуем с «тыс./млн», а не с числом.
    const rule = count >= 1000 ? 'many' : pluralRules.select(count);
    const word = rule === 'one' ? 'просмотр' : rule === 'few' ? 'просмотра' : 'просмотров';

    return `${compactFormat.format(count)} ${word}`;
}

const UNITS = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];

export function formatBytes(bytes: number | null | undefined): string {
    if (!bytes) {
        return '0 Б';
    }

    const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), UNITS.length - 1);
    const value = bytes / 1024 ** exponent;

    return `${value.toLocaleString('ru-RU', { maximumFractionDigits: value >= 100 ? 0 : 1 })} ${UNITS[exponent]}`;
}

const DIVISIONS: [number, Intl.RelativeTimeFormatUnit][] = [
    [60, 'second'],
    [60, 'minute'],
    [24, 'hour'],
    [7, 'day'],
    [4.34524, 'week'],
    [12, 'month'],
    [Number.POSITIVE_INFINITY, 'year'],
];

/** «3 дня назад», «в прошлом месяце». */
export function formatRelative(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    let duration = (new Date(iso).getTime() - Date.now()) / 1000;

    for (const [amount, unit] of DIVISIONS) {
        if (Math.abs(duration) < amount) {
            return relativeFormat.format(Math.round(duration), unit);
        }
        duration /= amount;
    }

    return '';
}

export function formatDate(iso: string | null | undefined): string {
    return iso ? dateFormat.format(new Date(iso)) : '';
}

export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase())
        .join('');
}
