(() => {
    const script = document.currentScript;
    if (!script || document.querySelector('.cu-help-launcher')) return;
    const url = new URL(script.dataset.supportUrl || '/help', location.origin);
    if (url.origin !== location.origin) return;
    const label = script.dataset.supportLabel || 'Support';
    const style = document.createElement('link');
    style.rel = 'stylesheet';
    style.href = new URL('../css/clubunio-help-widget.css?v=workspace-1', script.src).href;
    document.head.append(style);
    const link = document.createElement('a');
    link.className = 'cu-help-launcher';
    link.href = url.href;
    link.title = label;
    link.setAttribute('aria-label', label);
    const icon = document.createElement('span');
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = '?';
    const text = document.createElement('span');
    text.textContent = label;
    link.append(icon, text);
    const dialog = document.createElement('dialog');
    dialog.className = 'cu-help-panel';
    dialog.setAttribute('aria-label', label);
    const header = document.createElement('header');
    const title = document.createElement('strong');
    title.textContent = label;
    const close = document.createElement('button');
    close.type = 'button';
    close.textContent = '\u00d7';
    close.setAttribute('aria-label', script.dataset.closeLabel || 'Close');
    close.title = close.getAttribute('aria-label');
    const frame = document.createElement('iframe');
    frame.title = label;
    frame.referrerPolicy = 'no-referrer';
    // Only the trusted, same-origin support application is embedded.
    header.append(title, close);
    dialog.append(header, frame);
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => link.focus());
    link.addEventListener('click', (event) => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (!frame.hasAttribute('src')) {
            const embedded = new URL(url.href);
            embedded.searchParams.set('embed', '1');
            frame.src = embedded.href;
        }
        dialog.showModal();
        close.focus();
    });
    document.body.append(link, dialog);
})();
