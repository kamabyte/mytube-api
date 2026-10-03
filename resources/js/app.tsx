import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { FlashToaster } from '@/components/flash-toaster';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';

void createInertiaApp({
    title: (title) => (title ? `${title} · MyTube` : 'MyTube'),
    layout: () => AppLayout,
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={300}>
                {app}
                <FlashToaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#ff453a',
        delay: 150,
    },
});

initializeTheme();
