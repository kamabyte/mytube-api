export interface InstallPromptEvent extends Event {
    prompt(): Promise<unknown>;
}

export function isStandaloneApp() {
    return window.matchMedia('(display-mode: standalone)').matches
        || (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

export function initializeStandaloneApp() {
    if (!isStandaloneApp()) return;

    document.documentElement.dataset.standaloneApp = '';

    const keepBrowserActions = '[data-selectable], input, textarea, select, [contenteditable], a[target="_blank"], a[download]';
    const suppressBrowserAction = (event: Event) => {
        if (event.target instanceof Element && !event.target.closest(keepBrowserActions)) event.preventDefault();
    };
    document.addEventListener('contextmenu', suppressBrowserAction);
    document.addEventListener('dragstart', suppressBrowserAction);

    if (navigator.maxTouchPoints > 0) {
        const viewport = document.querySelector<HTMLMetaElement>('meta[name="viewport"]');
        if (viewport) viewport.content += ', maximum-scale=1, user-scalable=no';
        document.addEventListener('gesturestart', (event) => event.preventDefault(), { passive: false });
    }
}

export function registerServiceWorker() {
    if (import.meta.env.PROD && 'serviceWorker' in navigator && window.isSecureContext) {
        void navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' }).catch((error: unknown) => {
            console.warn('MyTube: не удалось запустить service worker', error);
        });
    }
}
