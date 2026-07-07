import './bootstrap';
// Chat Alpine components (chatLayout/chatComposer/chatMessages) MUST be
// registered before Livewire starts Alpine. A conditional dynamic import
// races Alpine's auto-start (and never runs when entering /chat via
// wire:navigate from another page), leaving `x-data="chatLayout(...)"`
// unresolved — which throws "resetDraggingFile is not defined" and blanks the
// shell. Importing statically guarantees registration on the alpine:init hook
// before Alpine boots, on every page and every navigation.
import './chat-page';

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
