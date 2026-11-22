import { WifiOff } from 'lucide-react';
import { useSyncExternalStore } from 'react';

function subscribe(listener: () => void) {
    window.addEventListener('online', listener);
    window.addEventListener('offline', listener);
    return () => {
        window.removeEventListener('online', listener);
        window.removeEventListener('offline', listener);
    };
}

export function NetworkStatus() {
    const online = useSyncExternalStore(subscribe, () => navigator.onLine, () => true);
    return (
        <div role="status">
            {!online && (
                <div className="flex items-center justify-center gap-2 border-b bg-muted px-4 py-2 text-sm">
                    <WifiOff className="size-4 shrink-0" aria-hidden />
                    Нет подключения к сети
                </div>
            )}
        </div>
    );
}
