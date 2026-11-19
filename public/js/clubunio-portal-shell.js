(() => {
    const toggle = document.querySelector('.cu-portal-menu-toggle');
    if (!toggle) return;

    const sidebar = document.getElementById(toggle.getAttribute('aria-controls'));
    const mobile = window.matchMedia('(max-width: 820px)');
    const close = () => toggle.setAttribute('aria-expanded', 'false');
    toggle.closest('.cu-portal-shell').classList.add('has-menu-toggle');
    toggle.addEventListener('click', () => {
        toggle.setAttribute('aria-expanded', String(toggle.getAttribute('aria-expanded') !== 'true'));
    });
    mobile.addEventListener('change', close);
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || toggle.getAttribute('aria-expanded') !== 'true') return;
        close();
        toggle.focus();
    });
    sidebar.addEventListener('click', (event) => {
        if (mobile.matches && event.target.closest('a')) {
            close();
            toggle.focus();
        }
    });
})();
