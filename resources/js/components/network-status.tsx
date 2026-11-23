import { router, usePage } from '@inertiajs/react';
import { WifiOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import { openDeviceLibrary } from '@/components/device-library-link';
import { DEVICE_LIBRARY, supportsOfflineVideo } from '@/lib/offline-video';

export function NetworkStatus() {
    const { component, url } = usePage();
    const [online, setOnline] = useState(() => navigator.onLine);

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
                setOnline(false);
                return;
            }
            if (pending) return;

            const controller = new AbortController();
            pending = controller;
            const timeout = window.setTimeout(() => controller.abort(), 3000);
            // Возврат из фона и отключение VPN не всегда меняют navigator.onLine.
            void fetch('/up', { method: 'HEAD', cache: 'no-store', redirect: 'error', signal: controller.signal })
                .then((response) => response.ok, () => false)
                .then((reachable) => {
                    window.clearTimeout(timeout);
                    if (pending !== controller) return;
                    pending = undefined;
                    if (!document.hidden) setOnline(reachable);
                });
        }

        function reconnect() {
            cancelCheck();
            check();
        }

        const removeSuccess = router.on('success', () => {
            if (!document.hidden && navigator.onLine) setOnline(true);
        });
        document.addEventListener('visibilitychange', check);
        window.addEventListener('pageshow', check);
        window.addEventListener('focus', check);
        window.addEventListener('online', reconnect);
        window.addEventListener('offline', check);
        check();
        return () => {
            cancelCheck();
            removeSuccess();
            document.removeEventListener('visibilitychange', check);
            window.removeEventListener('pageshow', check);
            window.removeEventListener('focus', check);
            window.removeEventListener('online', reconnect);
            window.removeEventListener('offline', check);
        };
    }, []);

    useEffect(() => {
        if (online || component === 'on-device' || !supportsOfflineVideo()) return;

        router.cancelAll({ prefetch: false });
        router.flushAll();
        const videoId = url.match(/^\/watch\/(\d+)(?:[?#]|$)/)?.[1];
        openDeviceLibrary({ url: videoId ? `${DEVICE_LIBRARY}?video=${videoId}` : DEVICE_LIBRARY, replace: true });
    }, [online, component, url]);

    return (
        <div role="status">
            {!online && (
                <div className="flex items-center justify-center gap-2 border-b bg-muted px-4 py-2 text-sm">
                    <WifiOff className="size-4 shrink-0" aria-hidden />
                    Нет связи с сервером MyTube
                </div>
            )}
        </div>
    );
}
