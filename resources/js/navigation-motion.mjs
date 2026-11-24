export function navigationMotion() {
    const sidebar = document.querySelector('.fi-sidebar');
    if (!sidebar || sidebar.dataset.cuMotionReady) return;
    sidebar.dataset.cuMotionReady = '1';
    const marker = document.createElement('span');
    marker.className = 'cu-nav-orbit'; marker.setAttribute('aria-hidden', 'true');
    sidebar.append(marker);
    const active = () => [...sidebar.querySelectorAll('.fi-sidebar-item.fi-active > .fi-sidebar-item-btn, .fi-sidebar-item.fi-active > .cu-submenu-row > .fi-sidebar-item-btn')].filter(node => node.getClientRects().length).at(-1);
    let target;
    const move = node => {
        target?.classList.remove('cu-orbit-target');
        target = node || active();
        const icon = target?.querySelector('svg');
        if (!icon || !target.getClientRects().length) { marker.style.opacity = '0'; return; }
        target.classList.add('cu-orbit-target');
        const base = sidebar.getBoundingClientRect(), box = icon.getBoundingClientRect();
        marker.style.transform = `translate(${box.left - base.left + box.width / 2 - 20}px, ${box.top - base.top + box.height / 2 - 20}px)`;
        marker.style.opacity = '1';
    };
    sidebar.querySelectorAll('.fi-sidebar-item-btn').forEach(link => {
        const label = link.querySelector('.fi-sidebar-item-label')?.textContent?.trim();
        if (label) { link.title = label; link.setAttribute('aria-label', label); }
    });
    sidebar.addEventListener('pointerover', event => { const link = event.target.closest('.fi-sidebar-item-btn'); if (link) move(link); });
    sidebar.addEventListener('pointerleave', () => move(active()));
    sidebar.addEventListener('focusin', event => move(event.target.closest('.fi-sidebar-item-btn')));
    sidebar.addEventListener('focusout', () => requestAnimationFrame(() => { if (!sidebar.contains(document.activeElement)) move(active()); }));
    sidebar.querySelector('.fi-sidebar-nav')?.addEventListener('scroll', () => move(target), { passive: true });
    sidebar.addEventListener('click', () => requestAnimationFrame(() => move(target)));
    new ResizeObserver(() => move(target)).observe(sidebar);
    window.addEventListener('resize', () => move(active()), { passive: true });
    requestAnimationFrame(() => move(active()));
}

export function setNavigationMotion(mode) {
    const full = mode === 'full' || (mode !== 'reduced' && !matchMedia('(prefers-reduced-motion: reduce)').matches);
    document.documentElement.dataset.cuMotion = full ? 'full' : 'reduced';
    let style = document.querySelector('#cu-navigation-transition');
    if (!style) { style = document.createElement('style'); style.id = 'cu-navigation-transition'; document.head.append(style); }
    style.textContent = `@view-transition { navigation: ${full ? 'auto' : 'none'}; }`;
    return full;
}
