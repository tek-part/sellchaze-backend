(() => {
    if (window.parent === window) return;
    const origin = window.location.origin;
    window.addEventListener('message', (event) => {
        const message = event.data;
        if (event.origin !== origin || !message || message.channel !== 'sellchaze-theme-studio' || message.version !== 1) return;
        if (message.type === 'hydrate' && message.payload) {
            document.documentElement.lang = message.payload.locale === 'ar' ? 'ar' : 'en';
            document.documentElement.dir = message.payload.locale === 'ar' ? 'rtl' : 'ltr';
            for (const section of message.payload.sections || []) {
                const node = document.querySelector(`[data-studio-section-id="${CSS.escape(String(section.id))}"]`);
                if (node) node.hidden = section.is_visible === false;
            }
            window.__SELLCHAZE_STUDIO_STATE__ = message.payload;
        }
    });
    document.addEventListener('click', (event) => {
        const node = event.target.closest?.('[data-studio-section-id]');
        if (!node) return;
        window.parent.postMessage({ channel: 'sellchaze-theme-preview', version: 1, type: 'section-selected', payload: { id: node.dataset.studioSectionId } }, origin);
    });
    window.parent.postMessage({ channel: 'sellchaze-theme-preview', version: 1, type: 'ready' }, origin);
})();
