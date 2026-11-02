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
            const searchToggle = control.querySelector('[data-cu-search-toggle]');
            const searchPanel = control.querySelector('#cu-menu-search-panel');
            const resizer = sidebar.querySelector('[data-cu-sidebar-resize]');
            const key = `clubunio.sidebar.width.${control.dataset.user}`;
            const resize = value => {
                const pixels = Math.round(Math.min(420, Math.max(240, Number(value) || 280)));
                resizer?.setAttribute('aria-valuenow', String(pixels));
                document.documentElement.style.setProperty('--sidebar-width', `${pixels}px`);
                return pixels;
            };
            try { resize(localStorage.getItem(key)); } catch { resize(280); }
            const rememberWidth = value => {
                const pixels = resize(value);
                try { localStorage.setItem(key, String(pixels)); } catch { /* Storage may be disabled. */ }
            };
            let drag = null;
            resizer?.addEventListener('pointerdown', event => {
                if (event.button !== 0 || !window.matchMedia('(min-width: 1024px)').matches) return;
                event.preventDefault();
                drag = { pointer: event.pointerId, x: event.clientX, width: sidebar.getBoundingClientRect().width,
                    direction: getComputedStyle(sidebar).direction === 'rtl' ? -1 : 1 };
                resizer.setPointerCapture(event.pointerId);
                document.documentElement.classList.add('cu-sidebar-resizing');
            });
            resizer?.addEventListener('pointermove', event => {
                if (!drag || drag.pointer !== event.pointerId) return;
                resize(drag.width + (event.clientX - drag.x) * drag.direction);
            });
            const finishDrag = event => {
                if (!drag || drag.pointer !== event.pointerId) return;
                rememberWidth(event.type === 'pointercancel' ? drag.width : resizer.getAttribute('aria-valuenow'));
                drag = null;
                document.documentElement.classList.remove('cu-sidebar-resizing');
                if (resizer.hasPointerCapture(event.pointerId)) resizer.releasePointerCapture(event.pointerId);
            };
            resizer?.addEventListener('pointerup', finishDrag);
            resizer?.addEventListener('pointercancel', finishDrag);
            resizer?.addEventListener('lostpointercapture', finishDrag);
            resizer?.addEventListener('keydown', event => {
                const current = Number(resizer.getAttribute('aria-valuenow'));
                const direction = getComputedStyle(sidebar).direction === 'rtl' ? -1 : 1;
                const next = { ArrowLeft: current - 10 * direction, ArrowRight: current + 10 * direction, Home: 240, End: 420 }[event.key];
                if (next === undefined) return;
                event.preventDefault();
                rememberWidth(next);
            });
            resizer?.addEventListener('dblclick', () => rememberWidth(280));
            const toggleSearch = open => {
                searchPanel.hidden = !open;
                searchToggle.setAttribute('aria-expanded', String(open));
                if (open) search.focus();
                else {
                    search.value = '';
                    search.dispatchEvent(new Event('input'));
                    searchToggle.focus();
                }
            };
            searchToggle.addEventListener('click', () => toggleSearch(searchPanel.hidden));
            search.addEventListener('keydown', event => {
                if (event.key === 'Escape') { event.preventDefault(); toggleSearch(false); }
            });
            const collapsedBeforeSearch = new Map();
            search.addEventListener('input', () => {
                const query = search.value.trim().toLocaleLowerCase();
                let matches = 0;
                sidebar.querySelectorAll('.fi-sidebar-group').forEach(group => {
                    const toggle = group.querySelector('.fi-sidebar-group-collapse-btn');
                    if (query && toggle && !collapsedBeforeSearch.has(group)) {
                        collapsedBeforeSearch.set(group, toggle.getAttribute('aria-expanded') === 'false');
                    }
                    let visible = 0;
                    const groupMatch = group.dataset.groupLabel?.toLocaleLowerCase().includes(query);
                    [...group.querySelectorAll('.fi-sidebar-item')].reverse().forEach(item => {
                        const label = item.querySelector('.fi-sidebar-item-label')?.textContent || '';
                        const searchable = `${label} ${item.dataset.cuSearchLabel || ''}`;
                        const matchingChild = [...item.querySelectorAll('.fi-sidebar-item')]
                            .some(child => !child.classList.contains('cu-search-hidden'));
                        const show = !query || groupMatch || searchable.toLocaleLowerCase().includes(query) || matchingChild;
                        item.classList.toggle('cu-search-hidden', !show);
                        if (show) visible++;
                    });
                    group.classList.toggle('cu-search-hidden', visible === 0);
                    // Let Alpine manage visibility, hidden attributes and accessibility together.
                    if (toggle && query && visible > 0 && toggle.getAttribute('aria-expanded') === 'false') toggle.click();
                    if (!query && toggle && collapsedBeforeSearch.has(group)) {
                        if ((toggle.getAttribute('aria-expanded') === 'false') !== collapsedBeforeSearch.get(group)) toggle.click();
                        collapsedBeforeSearch.delete(group);
                    }
                    matches += visible;
                });
                sidebar.querySelectorAll('[data-cu-submenu]').forEach(item => {
                    item.dispatchEvent(new CustomEvent('cu-submenu-search', { detail: { active: !!query } }));
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
