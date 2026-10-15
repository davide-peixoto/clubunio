(() => {
    if (window.clubUnioNavigationLoaded) return;
    window.clubUnioNavigationLoaded = true;
    const init = () => {
        document.querySelectorAll('[data-cu-navigation]').forEach(control => {
            if (control.dataset.ready) return;
            control.dataset.ready = '1';
            const sidebar = control.closest('.fi-sidebar');
            if (!sidebar) return;
            const search = control.querySelector('input[type=search]');
            const width = control.querySelector('input[type=range]');
            const key = `clubunio.sidebar.width.${control.dataset.user}`;
            const resize = value => {
                const pixels = Math.min(420, Math.max(240, Number(value) || 280));
                width.value = pixels;
                document.documentElement.style.setProperty('--sidebar-width', `${pixels}px`);
                return pixels;
            };
            try { resize(localStorage.getItem(key)); } catch { resize(280); }
            width.addEventListener('input', () => {
                const pixels = resize(width.value);
                try { localStorage.setItem(key, String(pixels)); } catch { /* Storage may be disabled. */ }
            });
            search.addEventListener('input', () => {
                const query = search.value.trim().toLocaleLowerCase();
                let matches = 0;
                sidebar.querySelectorAll('.fi-sidebar-group').forEach(group => {
                    let visible = 0;
                    const groupMatch = group.dataset.groupLabel?.toLocaleLowerCase().includes(query);
                    group.querySelectorAll('.fi-sidebar-item').forEach(item => {
                        const label = item.querySelector('.fi-sidebar-item-label')?.textContent || '';
                        const show = !query || groupMatch || label.toLocaleLowerCase().includes(query);
                        item.classList.toggle('cu-search-hidden', !show);
                        if (show) visible++;
                    });
                    group.classList.toggle('cu-search-hidden', visible === 0);
                    group.classList.toggle('cu-search-open', !!query && visible > 0);
                    matches += visible;
                });
                control.querySelector('.cu-nav-empty').hidden = matches > 0 || !query;
                const settings = control.querySelector('.cu-settings-shortcut');
                if (settings) {
                    settings.hidden = !query;
                    const url = new URL(settings.dataset.settingsUrl, location.origin);
                    url.searchParams.set('search', search.value.trim());
                    settings.href = url.href;
                }
            });
        });
    };
    document.addEventListener('livewire:navigated', init);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
