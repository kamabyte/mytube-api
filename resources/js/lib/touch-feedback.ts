export function enableTouchFeedback() {
    let pressed: Element | null = null;

    function release() {
        pressed?.removeAttribute('data-pressed');
        pressed = null;
    }

    function isPrimaryTouch(event: PointerEvent) {
        return event.isPrimary && event.pointerType === 'touch';
    }

    function onEnd(event: PointerEvent) {
        if (isPrimaryTouch(event)) release();
    }

    const options = { passive: true, capture: true };
    document.addEventListener('pointerdown', (event) => {
        if (!isPrimaryTouch(event) || !(event.target instanceof Element)) return;

        release();
        pressed = event.target.closest('.pressable:not(:disabled, [aria-disabled="true"])');
        pressed?.setAttribute('data-pressed', '');
    }, options);
    document.addEventListener('pointerup', onEnd, options);
    document.addEventListener('pointercancel', onEnd, options);
    window.addEventListener('blur', release);
}
