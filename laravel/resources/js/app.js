import './bootstrap';

const syncAppViewportHeight = () => {
    const height = window.visualViewport?.height || window.innerHeight;

    if (!height) {
        return;
    }

    document.documentElement.style.setProperty('--app-viewport-height', `${height}px`);
};

syncAppViewportHeight();
window.addEventListener('resize', syncAppViewportHeight, { passive: true });
window.addEventListener('orientationchange', () => {
    window.setTimeout(syncAppViewportHeight, 80);
}, { passive: true });
window.visualViewport?.addEventListener('resize', syncAppViewportHeight, { passive: true });

if (document.querySelector('[data-ista-chat-shell]')) {
    import('./chat-page');
}
