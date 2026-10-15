(() => {
    if (window.ClubUnioSignage) { window.ClubUnioSignage.scan(); return; }
    const mounted = new WeakSet();
    const clock = new Intl.DateTimeFormat('de-CH', {timeZone:'Europe/Zurich',hour:'2-digit',minute:'2-digit',second:'2-digit'});
    const date = new Intl.DateTimeFormat('de-CH', {timeZone:'Europe/Zurich',weekday:'long',day:'numeric',month:'long',year:'numeric'});
    function mount(board) {
        if (mounted.has(board)) return;
        mounted.add(board);
        let groups = [], slide = 0, timer = 0, frame = 0;
        const mobile = () => window.matchMedia('(max-width:700px)').matches;
        function show(group, page) {
            group.page = page % group.pages.length;
            group.rows.forEach(row => { row.hidden = !group.pages[group.page].includes(row); });
        }
        function counters() {
            board.querySelectorAll('.sg-games,.sg-training').forEach(panel => {
                const active = groups.filter(g => panel.contains(g.body) && g.pages.length > 1);
                const label = panel.querySelector('.sg-page-count');
                if (label) label.textContent = active.map(g => `${g.page + 1}/${g.pages.length}`).join(' · ');
            });
        }
        function pictures() {
            const posts = [...board.querySelectorAll('.sg-instagram-slides article')];
            const count = Math.ceil(posts.length / 2);
            posts.forEach((post, i) => { post.hidden = count > 0 && Math.floor(i / 2) !== slide % count; });
        }
        function layout() {
            groups = [];
            board.querySelectorAll('.sg-pageable').forEach(body => {
                const table = body.querySelector('table');
                if (!table) return;
                const rows = [...table.querySelectorAll('tbody > tr')];
                rows.forEach(row => { row.hidden = false; });
                if (mobile()) return;
                const available = body.clientHeight - (table.tHead?.offsetHeight || 0) - 4;
                const pages = []; let page = [], used = 0;
                rows.forEach(row => {
                    const height = row.getBoundingClientRect().height;
                    if (page.length && used + height > available) { pages.push(page); page = []; used = 0; }
                    page.push(row); used += height;
                });
                if (page.length) pages.push(page);
                if (pages.length) { const group = {body, rows, pages, page:0}; groups.push(group); show(group, 0); }
            });
            pictures(); counters();
        }
        const queue = () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(layout); };
        const observer = new MutationObserver(records => {
            if (records.some(r => !r.target.parentElement?.closest('.sg-clock,.sg-date,.sg-page-count') && !r.target.closest?.('.sg-clock,.sg-date,.sg-page-count'))) queue();
        });
        observer.observe(board, {childList:true,subtree:true});
        const resize = new ResizeObserver(queue); resize.observe(board);
        layout();
        timer = window.setInterval(() => {
            if (!board.isConnected) { clearInterval(timer); observer.disconnect(); resize.disconnect(); return; }
            board.querySelector('.sg-clock').textContent = clock.format(new Date());
            board.querySelector('.sg-date').textContent = date.format(new Date());
            board.querySelectorAll('.sg-scroll').forEach(body => {
                if (!mobile() && body.scrollHeight > body.clientHeight + 2) body.scrollTop = body.scrollTop + 1 >= body.scrollHeight - body.clientHeight ? 0 : body.scrollTop + 1;
            });
        }, 1000);
        const rotate = window.setInterval(() => {
            if (!board.isConnected) { clearInterval(rotate); return; }
            groups.forEach(group => show(group, group.page + 1)); slide++; pictures(); counters();
        }, 12000);
    }
    const scan = () => document.querySelectorAll('[data-signage]').forEach(mount);
    window.ClubUnioSignage = {scan};
    document.addEventListener('DOMContentLoaded', scan);
    document.addEventListener('livewire:navigated', scan);
    scan();
})();
