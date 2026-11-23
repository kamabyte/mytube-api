import { useEffect, useState } from 'react';
import { isStandaloneApp } from '@/lib/pwa';

export function useServerReachability(initiallyReachable = navigator.onLine) {
    const [reachable, setReachable] = useState(initiallyReachable);

    useEffect(() => {
        let pending: AbortController | undefined;

        function cancelCheck() {
            pending?.abort();
            pending = undefined;
        }

        function check() {
            if (document.hidden) {
                cancelCheck();
                return;
            }
            if (!navigator.onLine) {
                cancelCheck();
                setReachable(false);
                return;
            }
            if (pending) return;

            const controller = new AbortController();
            pending = controller;
            const timeout = window.setTimeout(() => controller.abort(), 3000);
            void fetch('/up', { method: 'HEAD', cache: 'no-store', redirect: 'error', signal: controller.signal })
                .then((response) => response.ok, () => false)
                .then((available) => {
                    window.clearTimeout(timeout);
                    if (pending !== controller) return;
                    pending = undefined;
                    if (!document.hidden) setReachable(available);
                });
        }

        function reconnect() {
            cancelCheck();
            check();
        }

        const interval = isStandaloneApp() ? window.setInterval(check, 15000) : undefined;
        document.addEventListener('visibilitychange', check);
        window.addEventListener('pageshow', check);
        window.addEventListener('focus', check);
        window.addEventListener('online', reconnect);
        window.addEventListener('offline', check);
        check();
        return () => {
            window.clearInterval(interval);
            cancelCheck();
            document.removeEventListener('visibilitychange', check);
            window.removeEventListener('pageshow', check);
            window.removeEventListener('focus', check);
            window.removeEventListener('online', reconnect);
            window.removeEventListener('offline', check);
        };
    }, []);

    return [reachable, setReachable] as const;
}
