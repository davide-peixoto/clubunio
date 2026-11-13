(() => {
    const script = document.currentScript;
    const url = new URL('/help', script.src);
    if (url.protocol !== 'https:' && url.origin !== location.origin) return;
    const english = document.documentElement.lang.toLowerCase().startsWith('en');
    const label = english ? 'Help & Support' : 'Hilfe & Support';
    const button = document.createElement('button');
    const dialog = document.createElement('dialog');
    const close = document.createElement('button');
    const frame = document.createElement('iframe');
    button.type = close.type = 'button';
    button.textContent = label;
    close.textContent = '×';
    close.setAttribute('aria-label', english ? 'Close' : 'Schliessen');
    dialog.setAttribute('aria-label', label);
    frame.title = label;
    frame.referrerPolicy = 'no-referrer';
    frame.setAttribute('sandbox', 'allow-scripts allow-same-origin');
    frame.style.cssText = 'width:100%;height:calc(100% - 42px);border:0';
    dialog.style.cssText = 'position:fixed;inset:0 0 0 auto;margin:0;width:min(100%,32rem);height:100dvh;max-width:100%;max-height:100%;padding:12px;border:0;box-sizing:border-box';
    button.onclick = () => {
        if (!frame.hasAttribute('src')) frame.src = url.href;
        dialog.showModal();
    };
    close.onclick = () => dialog.close();
    dialog.addEventListener('close', () => button.focus());
    dialog.append(close, frame);
    script.after(button, dialog);
})();
