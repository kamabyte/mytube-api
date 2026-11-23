import '../css/app.css';

import { House } from 'lucide-react';
import { createRoot } from 'react-dom/client';
import { DeviceLibrary } from '@/components/device-library';
import { Logo } from '@/components/logo';
import { Button } from '@/components/ui/button';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { useServerReachability } from '@/hooks/use-server-reachability';
import { DEVICE_LIBRARY } from '@/lib/offline-video';
import { initializeStandaloneApp, registerServiceWorker } from '@/lib/pwa';
import { enableTouchFeedback } from '@/lib/touch-feedback';

function OfflineApp() {
    const [online] = useServerReachability(location.pathname === DEVICE_LIBRARY && navigator.onLine);

    return (
        <main className="mx-auto min-h-dvh max-w-4xl px-4 pt-[env(safe-area-inset-top)] pb-[max(2rem,env(safe-area-inset-bottom))] sm:px-6">
            <header className="flex min-h-16 items-center justify-between gap-4 border-b border-border/60">
                <a href="/" aria-label="MyTube — главная"><Logo /></a>
                <Button asChild variant="ghost" className="min-h-11 rounded-full">
                    <a href="/"><House /> Главная</a>
                </Button>
            </header>
            <div className="mt-6">
                <DeviceLibrary disconnected={!online} />
            </div>
        </main>
    );
}

initializeTheme();
initializeStandaloneApp();
enableTouchFeedback();
registerServiceWorker();
createRoot(document.getElementById('mytube-device')!).render(<TooltipProvider><OfflineApp /></TooltipProvider>);
