import { configureEcho } from '@laravel/echo-react';

/**
 * Echo поверх Reverb. Ключ — из <meta name="reverb-key"> (образ один на все
 * окружения, VITE_* в нём не годятся), хост и порт — те же, что у страницы:
 * nginx образа проксирует /app в Reverb, так что работает и по домену, и по IP.
 * В npm run dev страницу отдаёт php artisan serve, а Reverb слушает свой порт.
 *
 * false — ключа нет (BROADCAST_CONNECTION не reverb): живых уведомлений нет.
 */
export function setupEcho(): boolean {
    const key = document.querySelector<HTMLMetaElement>('meta[name="reverb-key"]')?.content;
    if (!key) return false;

    const secure = window.location.protocol === 'https:';
    const port = Number(window.location.port) || (secure ? 443 : 80);
    const dev = import.meta.env.DEV;

    configureEcho({
        broadcaster: 'reverb',
        key,
        wsHost: dev ? (import.meta.env.VITE_REVERB_HOST ?? window.location.hostname) : window.location.hostname,
        wsPort: dev ? Number(import.meta.env.VITE_REVERB_PORT ?? 8080) : port,
        wssPort: port,
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
    });

    return true;
}
