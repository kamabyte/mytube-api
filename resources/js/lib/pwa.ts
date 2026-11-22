export interface InstallPromptEvent extends Event {
    prompt(): Promise<unknown>;
}

export function registerServiceWorker() {
    if (import.meta.env.PROD && 'serviceWorker' in navigator && window.isSecureContext) {
        void navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' }).catch((error: unknown) => {
            console.warn('MyTube: не удалось запустить service worker', error);
        });
    }
}
