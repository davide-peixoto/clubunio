(() => {
    if (window.clubunioWorkHubKeyboard) return;
    window.clubunioWorkHubKeyboard = true;
    document.addEventListener('keydown', event => {
        if ((event.ctrlKey || event.metaKey) && event.shiftKey && event.key.toLowerCase() === 'f' && !event.altKey) {
            const link = document.querySelector('[data-object-search-link]');
            if (!link || document.querySelector('dialog[open]') || [...document.querySelectorAll('[role=dialog][aria-modal=true]')].some(node => node.getClientRects().length)) return;
            event.preventDefault(); link.click();
        }
    });
})();
