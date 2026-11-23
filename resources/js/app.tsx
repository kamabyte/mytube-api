import '../css/app.css';

import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { FlashToaster } from '@/components/flash-toaster';
import { LiveNotifications } from '@/components/live-notifications';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import { setupEcho } from '@/lib/echo';
import { initializeStandaloneApp, registerServiceWorker } from '@/lib/pwa';
import { enableTouchFeedback } from '@/lib/touch-feedback';
import OnDevice from '@/pages/on-device';

const live = setupEcho();
const pages = import.meta.glob<{ default: ResolvedComponent }>(['./pages/**/*.tsx', '!./pages/on-device.tsx']);

void createInertiaApp({
    title: (title) => (title ? `${title} · MyTube` : 'MyTube'),
    resolve: async (name) => {
        // Локальная библиотека открывается из уже загруженного приложения даже без сети.
        if (name === 'on-device') return OnDevice;
        const page = pages[`./pages/${name}.tsx`];
        if (!page) throw new Error(`Page not found: ${name}`);
        return (await page()).default;
    },
    layout: () => AppLayout,
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={300}>
                {app}
                <FlashToaster />
                <LiveNotifications live={live} />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#ff453a',
        delay: 150,
    },
});

initializeTheme();
initializeStandaloneApp();
enableTouchFeedback();
registerServiceWorker();
