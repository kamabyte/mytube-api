import { router, usePage } from '@inertiajs/react';
import { WifiOff } from 'lucide-react';
import { useEffect } from 'react';
import { openDeviceLibrary } from '@/components/device-library-link';
import { useServerReachability } from '@/hooks/use-server-reachability';
import { DEVICE_LIBRARY, supportsOfflineVideo } from '@/lib/offline-video';

export function NetworkStatus() {
    const { component, url } = usePage();
    const [online, setOnline] = useServerReachability();

    useEffect(
        () => router.on('success', () => {
            if (!document.hidden && navigator.onLine) setOnline(true);
        }),
        [setOnline],
    );

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
