import './social-studio.css';

const dimensions = { portrait: 1350, square: 1080 };
const channels = ['instagram', 'facebook'];
const tokens = ['club', 'date', 'fixtures', 'home', 'away', 'score', 'time', 'place'];
const clone = value => JSON.parse(JSON.stringify(value));
const limit = (value, min, max) => Math.min(max, Math.max(min, value));
const el = (tag, text = '', props = {}) => Object.assign(document.createElement(tag), { textContent: text, ...props });
const box = (...children) => { const node = el('div', '', { className: 'cu-social-actions' }); node.append(...children); return node; };
const labeled = (text, input) => { const node = el('label', '', { className: 'cu-social-field' }); node.append(el('span', text), input); return node; };
const baseLayer = (type, x, y, w, h, extra = {}) => ({ type, x, y, w, h, angle: 0, ...extra });

export function defaultDesign(kind, labels) {
    const text = (value, y, size, fill, bold = false) => baseLayer('text', 72, y, 936, size + 30, { text: value, size, fill, bold });
    const field = (value, y, size, fill, align = 'left', height = 145) => baseLayer('field', 72, y, 936, height, { field: value, size, fill, align, bold: true });
    const layers = [
        baseLayer('rect', 72, 93, 148, 10, { fill: '#78e0c5', locked: true }),
        field('club', 122, 44, '#a4c9ed'),
        text(labels[kind + '_heading'], 240, 88, '#ffffff', true),
    ];
    if (kind === 'day') layers.push(field('date', 380, 36, '#78e0c5'), field('fixtures', 488, 35, '#ffffff', 'left', 730));
    if (kind === 'result') layers.push(field('home', 468, 62, '#ffffff', 'center'), field('score', 620, 156, '#78e0c5', 'center', 200), field('away', 850, 62, '#ffffff', 'center'));
    return { format: 'portrait', background: '#183454', layers };
}

export function snapshotFor(kind, date, fixtures) {
    if (kind === 'free') return [];
    return { date, fixtures: fixtures.map(({ score, ...fixture }) => kind === 'result' ? { ...fixture, score } : fixture) };
}

export function fieldValue(key, snapshot, club) {
    const rows = snapshot?.fixtures ?? [];
    const first = rows[0] ?? {};
    if (key === 'club') return club ?? '';
    if (key === 'date') return snapshot?.date?.split('-').reverse().join('.') ?? '';
    if (key === 'fixtures') return rows.map(f => [f.time, f.home + ' – ' + f.away, f.place].filter(Boolean).join('  ·  ')).join('\n\n');
    if (key === 'score') return first.score?.join(' : ') ?? '';
    return first[key] ?? '';
}

function mount(root) {
    const config = JSON.parse(root.dataset.config);
    const $ = selector => root.querySelector(selector);
    const t = key => config.labels[key] ?? key;
    const workspace = $('[data-workspace]');
    const message = $('[data-message]');
    const dialog = $('[data-studio-dialog]');
    const editorError = $('[data-editor-error]');
    const date = $('[data-date]');
    const board = $('[data-board]');
    const archive = $('[data-archived]');
    const pages = { posts: 1, templates: 1, media: 1 };
    const demo = { date: '2026-09-12', fixtures: [{ time: '15:00', home: t('sample_home'), away: t('sample_away'), place: 'Arena 1', score: [3, 1] }] };
    let state, jobs, tab = 'today', selection = new Set(), editor, canvas, fabric, generation = 0, pending, busy = false, hydrating = false;
    const src = new Map();

    function url(op, params = {}) {
        const link = new URL(config.url.replace(/state$/, op), location.origin);
        Object.entries(config.context ?? {}).forEach(([key, value]) => link.searchParams.set('context[' + key + ']', value));
        Object.entries(params).forEach(([key, value]) => { if (value !== '') link.searchParams.set(key, value); });
        return link;
    }
    async function request(op, params = {}, body = null, signal) {
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf };
        if (body && !(body instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(body);
        }
        const result = await fetch(url(op, params), { method: body ? 'POST' : 'GET', headers, body, signal, cache: 'no-store' });
        if (!result.ok) {
            const data = await result.json().catch(() => ({}));
            const validation = data.errors?.social?.[0];
            throw new Error(validation ?? ([409, 419].includes(result.status) ? t('stale') : t(body ? 'write_error' : 'read_error')));
        }
        return result.json();
    }
    function alert(node, text, error = false) {
        node.textContent = text;
        node.hidden = !text;
        node.toggleAttribute('data-error', error);
    }
    function button(key, action, { primary = false, disabled = false, icon = false, pressed, text } = {}) {
        const control = el('button', icon ? '' : text ?? t(key), { type: 'button', disabled });
        if (icon) control.append($('[data-icon="' + key + '"]').content.cloneNode(true));
        if (icon) { control.title = t(key); control.setAttribute('aria-label', t(key)); }
        if (primary) control.dataset.primary = '';
        if (pressed !== undefined) control.setAttribute('aria-pressed', pressed);
        control.addEventListener('click', action);
        return control;
    }
    async function perform(fn, inEditor = false) {
        if (busy) return;
        busy = true;
        alert(inEditor ? editorError : message, '');
        try { await fn(); } catch (error) {
            alert(inEditor && dialog.open ? editorError : message, error.message || t('write_error'), true);
        } finally { busy = false; }
    }
    async function load() {
        pending?.abort();
        const current = pending = new AbortController();
        const section = tab;
        const params = { section, date: date.value };
        if (Object.hasOwn(pages, section)) Object.assign(params, { archived: archive.checked ? 1 : 0, [section + '_page']: pages[section] });
        root.querySelectorAll('[data-tab]').forEach(button => {
            const active = button.dataset.tab === section;
            button.setAttribute('aria-pressed', active);
            button.classList.toggle('fi-active', active);
        });
        $('[data-archive]').hidden = section === 'today' || section === 'jobs';
        $('[data-date-label]').hidden = section !== 'today';
        workspace.setAttribute('aria-busy', 'true');
        workspace.inert = true;
        alert(message, '');
        try {
            const next = await request('state', params, null, current.signal);
            if (current !== pending) return;
            state = next;
            jobs = next.job_data;
            date.value = state.date;
            render();
        } catch (error) {
            if (current === pending) {
                workspace.replaceChildren();
                alert(message, error.message, true);
            }
        } finally {
            if (current === pending) {
                pending = null;
                workspace.inert = false;
                workspace.setAttribute('aria-busy', 'false');
            }
        }
    }
    const perm = key => Boolean(state?.permissions[key]);
    const row = (content, actions) => {
        const node = el('div', '', { className: 'cu-social-row' });
        node.append(content, box(...actions)); return node;
    };
    const list = rows => {
        if (!rows.length) return el('p', t('empty'));
        const node = el('div', '', { className: 'cu-social-list' }); node.append(...rows); return node;
    };
    const title = (main, sub = '') => { const node = el('div'); node.append(el('h3', main)); if (sub) node.append(el('span', sub)); return node; };
    const fixtureLabel = fixture => [fixture.time, fixture.home + ' – ' + fixture.away, fixture.place].filter(Boolean).join(' · ');
    function action(op, input, confirmKey = null) {
        if (confirmKey && !window.confirm(t(confirmKey))) return;
        return perform(async () => { await request(op, {}, input); await load(); alert(message, t('saved')); });
    }
    function download(post) {
        const a = el('a', t('download'), { href: url('image', { id: post.id, download: 1 }).href });
        a.dataset.button = '';
        return a;
    }
    function render() {
        if ($('[data-tab="jobs"]')) $('[data-tab="jobs"]').hidden = !perm('schedule');
        const items = [];
        if (tab === 'today') {
            const primary = box();
            if (perm('create')) {
                primary.append(button('day', () => openPost('day', state.fixtures.filter(f => selection.has(f.id))), {
                    primary: true, disabled: selection.size === 0,
                }), button('free', () => openPost('free', [])));
            }
            items.push(primary, list(state.fixtures.map(f => {
                const content = el('div');
                if (perm('create')) {
                    const check = el('input', '', { type: 'checkbox', checked: selection.has(f.id) });
                    const label = el('label'); label.append(check, el('span', fixtureLabel(f)));
                    check.onchange = () => {
                        if (check.checked && selection.size < 12) selection.add(f.id); else selection.delete(f.id);
                        render();
                        [...workspace.querySelectorAll('input[type=checkbox]')][state.fixtures.indexOf(f)]?.focus();
                    };
                    content.append(label);
                } else content.append(el('span', fixtureLabel(f)));
                if (f.score) content.append(el('strong', f.score.join(' : ')));
                const actions = [];
                if (perm('approve')) actions.push(button('confirm_score', () => resultForm(f)));
                if (perm('create') && f.score) actions.push(button('result', () => openPost('result', [f])));
                return row(content, actions);
            })));
        }
        if (tab === 'posts') {
            if (perm('create') && !archive.checked) items.push(button('free', () => openPost('free', []), { primary: true }));
            if (perm('send') && !state.test_channels.length) items.push(el('p', t('test_disabled')));
            items.push(list(state.posts.map(post => {
                const content = title(post.title, t(post.kind) + ' · ' + t(post.state));
                post.deliveries.forEach(item => {
                    const info = el('div', t(item.channel) + ': ' + t(item.state));
                    if (item.remote_id) info.append(document.createTextNode(' · ' + item.remote_id));
                    content.append(info);
                });
                const args = { id: post.id, revision: post.revision };
                const actions = [button('preview', () => previewPost(post))];
                if (perm('download')) actions.push(download(post));
                if (post.state !== 'archived') {
                    if (perm('update') && !post.deliveries.length && post.snapshot?.source !== 'sfv_final') actions.push(button('open', () => editPost(post)));
                    if (perm('approve') && post.state === 'draft') actions.push(button('approve', () => action('post-approve', args)));
                    if (perm('deactivate') && !post.deliveries.some(d => ['sending', 'unknown'].includes(d.state))) actions.push(button('archive', () => action('post-archive', args)));
                    if (perm('send') && post.state === 'approved') {
                        state.test_channels.filter(c => post.targets.includes(c)).forEach(channel => {
                            const delivery = post.deliveries.find(d => d.channel === channel);
                            if (!delivery || delivery.state === 'failed') actions.push(button('publish', () => perform(async () => {
                                if (!window.confirm(t('confirm_publish'))) return;
                                const output = await request('publish', {}, { ...args, channel }); await load();
                                alert(message, t(output.state), output.state !== 'published');
                            }), { text: t('publish') + ': ' + t(channel) }));
                        });
                    }
                }
                return row(content, actions);
            })));
        }
        if (tab === 'templates') {
            if (!archive.checked && perm('create')) items.push(box(...['day', 'result', 'free'].map(kind => button('new_template', () => openTemplate(kind), {
                primary: true, text: t('new_template') + ': ' + t(kind),
            }))));
            items.push(list(state.templates.map(template => {
                const args = { id: template.id, revision: template.revision };
                const actions = [button('open', () => openTemplate(null, template))];
                if (!template.archived && perm('copy')) actions.push(button('copy', () => {
                    const name = window.prompt(t('name'), template.name);
                    if (name?.trim()) action('template-copy', { ...args, name: name.trim() });
                }));
                if (template.archived ? perm('restore') : perm('deactivate')) actions.push(button(template.archived ? 'restore' : 'archive',
                    () => action(template.archived ? 'template-restore' : 'template-archive', args)));
                return row(title(template.name, t('version') + ' ' + template.revision), actions);
            })));
        }
        if (tab === 'media') {
            if (!archive.checked && perm('upload')) items.push(uploadControl(false));
            items.push(list(state.media.map(media => {
                const content = el('div', '', { className: 'cu-social-media' });
                content.append(el('img', '', { src: url('media', { id: media.id }).href, alt: '', loading: 'lazy' }), el('span', media.name));
                const actions = [];
                if (media.archived ? perm('restore') : perm('deactivate')) actions.push(button(media.archived ? 'restore' : 'archive',
                    () => action(media.archived ? 'media-restore' : 'media-archive', { id: media.id })));
                return row(content, actions);
            })));
        }
        if (tab === 'jobs' && jobs) {
            items.push(button('job_new', () => jobForm(), { primary: true, disabled: !jobs.templates.length || !jobs.teams.length }));
            if (!jobs.runner_enabled) items.push(el('p', t('job_runner_disabled')));
            items.push(list(jobs.jobs.map(job => row(title(job.name, [t(job.kind), job.local_time, t(job.enabled ? 'job_enabled' : 'job_disabled')].join(' · ')),
                [button('open', () => jobForm(job))]))));
            items.push(el('h2', t('job_runs')), list(jobs.runs.filter(run => !run.slot.startsWith('scan:')).map(run => row(title(
                jobs.jobs.find(job => job.id === run.job_id)?.name ?? t('jobs'), run.run_date + ' · ' + t(run.state)), []))));
        }
        if (tab !== 'today' && tab !== 'jobs') items.push(box(button('previous', () => { pages[tab]--; load(); }, { disabled: pages[tab] === 1 }),
            button('next', () => { pages[tab]++; load(); }, { disabled: !state.more[tab] })));
        workspace.replaceChildren(...items);
    }
    function uploadControl(inEditor) {
        const input = el('input', '', { type: 'file', accept: 'image/jpeg,image/png,image/webp', hidden: true });
        input.onchange = () => perform(async () => {
            const file = input.files[0];
            if (!file) return;
            const form = new FormData();
            form.append('file', file);
            form.append('name', file.name.slice(0, 120));
            const uploaded = await request('upload', {}, form);
            await load();
            if (inEditor && editor) {
                editor.mediaId = uploaded.id;
                if (!editor.media.some(item => item.id === uploaded.id)) editor.media.unshift({ id: uploaded.id, name: file.name.slice(0, 120) });
                drawPanels();
            }
            alert(inEditor ? editorError : message, t('saved'));
        }, inEditor).finally(() => { input.value = ''; });
        return box(button('upload', () => input.click()), input);
    }
    function auxiliary(heading, content) {
        const modal = el('dialog');
        const labelId = 'social-aux-' + Date.now();
        modal.setAttribute('aria-labelledby', labelId);
        modal.style.width = 'min(95vw, 42rem)';
        modal.append(box(el('h2', heading, { id: labelId }), button('close', () => modal.close())), content);
        root.append(modal);
        modal.addEventListener('close', () => modal.remove(), { once: true });
        modal.showModal();
        return modal;
    }
    function previewPost(post) {
        const content = el('div');
        const img = el('img', '', { alt: post.title, src: url('image', { id: post.id }).href });
        img.style.cssText = 'max-height:60vh;width:auto;display:block;margin:.6rem auto';
        content.append(img, el('p', post.caption));
        auxiliary(post.title, content);
    }
    function resultForm(fixture) {
        const form = el('form');
        const fields = [0, 1].map((_, index) => el('input', '', { type: 'number', min: '0', max: '999', required: true, value: fixture.score?.[index] ?? '' }));
        const error = el('p');
        error.setAttribute('role', 'alert');
        const submit = el('button', t('confirm_score'), { type: 'submit' });
        form.append(el('p', fixtureLabel(fixture)), box(labeled(t('home'), fields[0]), labeled(t('away'), fields[1])), submit, error);
        const modal = auxiliary(t('result'), form);
        form.onsubmit = async event => {
            event.preventDefault();
            if (submit.disabled || !window.confirm(t('confirm_result'))) return;
            submit.disabled = true;
            try {
                await request('confirm-result', {}, { date: state.date, fixture: fixture.id,
                    host_score: Number(fields[0].value), guest_score: Number(fields[1].value), seen: fixture });
                modal.close(); await load(); alert(message, t('saved'));
            } catch (failure) { error.textContent = failure.message; } finally { submit.disabled = false; }
        };
    }

    function jobForm(job = null) {
        const form = el('form');
        const fields = el('div', '', { className: 'cu-social-grid' });
        const name = el('input', '', { type: 'text', maxLength: 120, required: true, value: job?.name ?? '' });
        const time = el('input', '', { type: 'time', required: true, value: job?.local_time ?? '08:00' });
        const kind = select(['day', 'result'].map(key => [key, t(key)]), job?.kind ?? 'day', value => { if (!job) time.value = value === 'result' ? '02:00' : '08:00'; });
        const templateOptions = jobs.templates.map(item => ({ ...item, key: item.id + ':' + item.revision }));
        const pinned = job ? job.template_id + ':' + job.template_revision : null;
        if (pinned && jobs.saved_versions[job.id] && !templateOptions.some(item => item.key === pinned)) {
            templateOptions.unshift({ id: job.template_id, revision: job.template_revision, key: pinned,
                name: jobs.templates.find(item => item.id === job.template_id)?.name ?? t('archived') });
        }
        const template = select(templateOptions.map(item => [item.key, item.name + ' · ' + t('version') + ' ' + item.revision]),
            pinned ?? templateOptions[0]?.key, () => {});
        const locale = select(jobs.languages.map(key => [key, t('job_language_' + key)]), job?.locale ?? document.documentElement.lang.split('-')[0], () => {});
        if (!locale.value) locale.selectedIndex = 0;
        const caption = el('textarea', '', { rows: 3, maxLength: 2000, value: job?.caption ?? '' });
        const toggle = (key, checked, disabled = false) => {
            const input = el('input', '', { type: 'checkbox', checked, disabled });
            const label = el('label'); label.append(input, el('span', t(key))); return [input, label];
        };
        const [enabled, enabledLabel] = toggle('job_enabled', Boolean(job?.enabled));
        const [automatic, automaticLabel] = toggle('job_auto', Boolean(job?.auto_publish) && jobs.auto_allowed, !jobs.auto_allowed);
        const targets = channels.map(channel => [channel, ...toggle(channel, job ? job.targets.includes(channel) : true)]);
        const teams = el('select', '', { multiple: true, size: 7, required: true });
        jobs.teams.forEach(team => teams.add(new Option(team.name, team.id, false, job?.team_ids.includes(Number(team.id)) ?? false)));
        fields.append(labeled(t('name'), name), labeled(t('job_kind'), kind), labeled(t('job_time'), time),
            labeled(t('templates'), template), labeled(t('job_locale'), locale), labeled(t('job_teams'), teams),
            labeled(t('caption'), caption), labeled(t('targets'), box(...targets.map(item => item[2]))), enabledLabel, automaticLabel);
        const error = el('p'); error.setAttribute('role', 'alert');
        const submit = el('button', t('job_save'), { type: 'submit' });
        const preview = el('img', '', { hidden: true, alt: t('preview') });
        preview.style.cssText = 'max-height:22rem;width:auto;margin:.5rem auto';
        const showPreview = button('preview', () => {
            const picked = templateOptions.find(item => item.key === template.value);
            if (!picked) return;
            preview.hidden = false;
            preview.src = url('job-preview', { template_id: picked.id, template_revision: picked.revision }).href;
        });
        preview.onerror = () => { preview.hidden = true; error.textContent = t('image_error'); };
        form.append(fields, box(showPreview, submit), preview, error);
        const modal = auxiliary(job?.name ?? t('job_new'), form);
        form.onsubmit = async event => {
            event.preventDefault();
            if (submit.disabled || (automatic.checked && !window.confirm(t('job_confirm_auto')))) return;
            submit.disabled = true;
            try {
                const picked = templateOptions.find(item => item.key === template.value);
                await request('job-save', {}, { id: job?.id, revision: job?.revision, name: name.value,
                    kind: kind.value, local_time: time.value, template_id: picked?.id,
                    template_revision: picked?.revision,
                    locale: locale.value, caption: caption.value, enabled: enabled.checked, auto_publish: automatic.checked,
                    team_ids: [...teams.selectedOptions].map(option => Number(option.value)), targets: targets.filter(item => item[1].checked).map(item => item[0]) });
                modal.close(); await load(); alert(message, t('saved'));
            } catch (failure) { error.textContent = failure.message; } finally { submit.disabled = false; }
        };
    }

    function openTemplate(kind, template = null) {
        perform(async () => {
            const [catalog, saved] = await Promise.all([editorCatalog(), template ? request('template', { id: template.id }) : null]);
            start(catalog, {
                mode: 'template', kind: kind ?? 'free', name: template?.name ?? t(kind), id: template?.id,
                revision: template?.revision, snapshot: demo, readonly: template ? template.archived || !perm('update') : !perm('create'),
                design: saved?.design ?? defaultDesign(kind, config.labels),
            });
        });
    }
    function openPost(kind, selected) {
        perform(async () => {
            const snapshot = snapshotFor(kind, state.date, selected);
            start(await editorCatalog(), {
                mode: 'post', kind, name: t(kind), caption: '', targets: [...channels],
                snapshot, design: defaultDesign(kind, config.labels),
            });
        });
    }
    function editPost(post) {
        perform(async () => {
            const [catalog, saved, { fixtures: fresh }] = await Promise.all([
                editorCatalog(), request('template', { id: post.id, post: 1 }),
                post.kind === 'free' ? { fixtures: [] } : request('state', { section: 'today', date: post.snapshot.date }),
            ]);
            const matched = (post.snapshot.fixtures ?? []).map(f => fresh.find(now => now.id === f.id)).filter(Boolean);
            if (post.kind !== 'free' && (matched.length !== post.snapshot.fixtures.length || (post.kind === 'result' && !matched[0]?.score))) {
                throw new Error(t('stale'));
            }
            start(catalog, {
                mode: 'post', kind: post.kind, name: post.title, caption: post.caption, targets: post.targets, id: post.id,
                revision: post.revision, snapshot: snapshotFor(post.kind, post.snapshot.date, matched), design: saved.design,
            });
        });
    }
    async function editorCatalog() {
        const [catalog, library] = await Promise.all([request('state', { section: 'editor' }), fabric ?? import('./social-studio-canvas.js')]);
        fabric = library;
        return catalog;
    }
    function start({ media, templates }, options) {
        editor = { ...options, date: state.date, media, templates,
            active: -1, history: [clone(options.design)], cursor: 0, dirty: false };
        alert(editorError, '');
        dialog.showModal();
        if (!canvas) {
            canvas = new fabric.Canvas($('[data-canvas]'), { selection: false, preserveObjectStacking: true });
            canvas.on('selection:created', selectionChanged);
            canvas.on('selection:updated', selectionChanged);
            canvas.on('selection:cleared', () => {
                if (!hydrating && editor) { editor.active = -1; drawPanels(); }
            });
            canvas.on('object:modified', ({ target }) => {
                if (!editor || !target || target._studioIndex === undefined) return;
                const layer = editor.design.layers[target._studioIndex];
                if (!layer || layer.locked || editor.readonly) return;
                const width = target.getScaledWidth(), height = target.getScaledHeight();
                Object.assign(layer, { x: limit(target.left, -2160, 2160), y: limit(target.top, -2700, 2700),
                    angle: limit(target.angle, -360, 360), w: limit(width, 10, 2160), h: limit(height, 10, 2700) });
                if (layer.type === 'text' || layer.type === 'field') layer.size = limit(Math.round((layer.size ?? 50) * target.scaleY), 12, 180);
                changed();
            });
            new ResizeObserver(() => { if (dialog.open && canvas && editor) resize(); }).observe(board);
        }
        drawDetails(); drawPanels(); redraw().catch(failImage);
    }
    const failImage = () => alert(editorError, t('image_error'), true);
    function selectionChanged(event) {
        if (hydrating || !editor) return;
        const index = event.selected?.[0]?._studioIndex;
        if (index !== undefined) { editor.active = index; drawPanels(); }
    }
    function resize() {
        const h = dimensions[editor.design.format];
        const width = Math.max(120, board.clientWidth - 18);
        const ratio = Math.min(width / 1080, (innerHeight * .58) / h);
        const next = { width: Math.floor(1080 * ratio), height: Math.floor(h * ratio) };
        if (next.width !== canvas.getWidth() || next.height !== canvas.getHeight()) canvas.setDimensions(next);
        canvas.setViewportTransform([ratio, 0, 0, ratio, 0, 0]);
    }
    async function imageSource(id) {
        if (!src.has(id)) src.set(id, fabric.FabricImage.fromURL(url('media', { id }).href).then(img => img.getElement()).catch(error => {
            src.delete(id); throw error;
        }));
        return src.get(id);
    }
    async function redraw() {
        const { FabricImage, Rect, Textbox } = fabric;
        const serial = ++generation;
        const current = editor;
        if (!current) return false;
        const items = await Promise.all(current.design.layers.map(async (layer, index) => {
            const opts = { originX: 'left', originY: 'top', left: layer.x, top: layer.y, angle: layer.angle,
                selectable: !current.readonly && !layer.locked, evented: !current.readonly && !layer.locked,
                strokeWidth: 0 };
            let obj;
            if (layer.type === 'image') {
                const img = await imageSource(layer.media);
                const iw = img.naturalWidth || img.width, ih = img.naturalHeight || img.height;
                let crop = layer.crop;
                if (!crop) {
                    const aspect = layer.w / layer.h;
                    const cw = Math.min(iw, ih * aspect), ch = Math.min(ih, iw / aspect);
                    crop = { x: (iw - cw) / 2 / iw, y: (ih - ch) / 2 / ih, w: cw / iw, h: ch / ih };
                }
                obj = new FabricImage(img, { ...opts, width: iw * crop.w, height: ih * crop.h, cropX: iw * crop.x,
                    cropY: ih * crop.y, scaleX: layer.w / (iw * crop.w), scaleY: layer.h / (ih * crop.h), flipX: layer.flipX ?? false });
            } else if (layer.type === 'rect') {
                obj = new Rect({ ...opts, width: layer.w, height: layer.h, fill: layer.fill ?? '#ffffff' });
            } else {
                const value = layer.type === 'field' ? fieldValue(layer.field, current.snapshot, state.club) : layer.text;
                obj = new Textbox(value || '', { ...opts, width: layer.w, fontFamily: 'Arial, sans-serif', fontSize: layer.size ?? 50,
                    fill: layer.fill ?? '#ffffff', fontWeight: layer.bold ? 'bold' : 'normal', textAlign: layer.align ?? 'left',
                    editable: false, splitByGrapheme: false });
                if (layer.type === 'field') {
                    for (let size = obj.fontSize; size > 12 && obj.height > layer.h; size--) obj.set('fontSize', size - 1);
                }
                obj.setControlsVisibility({ mt: false, mb: false });
            }
            obj._studioIndex = index;
            return obj;
        }));
        if (serial !== generation || !editor || !dialog.open) return false;
        hydrating = true;
        try {
            canvas.discardActiveObject();
            canvas.remove(...canvas.getObjects());
            canvas.backgroundColor = current.design.background;
            canvas.add(...items);
            resize();
            const active = items[current.active];
            if (active?.selectable) canvas.setActiveObject(active);
            canvas.renderAll();
        } finally { hydrating = false; }
        return true;
    }
    function changed(panels = true) {
        editor.dirty = true;
        editor.history = [...editor.history.slice(0, editor.cursor + 1), clone(editor.design)].slice(-30);
        editor.cursor = editor.history.length - 1;
        if (panels) drawPanels();
        redraw().catch(failImage);
    }
    function history(direction) {
        editor.cursor += direction;
        editor.design = clone(editor.history[editor.cursor]);
        editor.active = -1; editor.dirty = true; drawDetails(); drawPanels(); redraw().catch(failImage);
    }
    function addLayer(type, background = false) {
        if (editor.design.layers.length >= 30 || (type === 'image' && !editor.mediaId)) return;
        const extra = type === 'image' ? { media: editor.mediaId } : type === 'field' ? { field: editor.field ?? 'club', size: 50 }
            : type === 'text' ? { text: t('text'), size: 50 } : { fill: '#78e0c5' };
        const layer = background ? baseLayer(type, 0, 0, 1080, dimensions[editor.design.format], extra)
            : baseLayer(type, 140, 400, type === 'rect' ? 300 : 780, type === 'image' ? 500 : 160, extra);
        if (background) { editor.design.layers.unshift(layer); editor.active = 0; }
        else { editor.active = editor.design.layers.length; editor.design.layers.push(layer); }
        changed();
    }
    function select(options, current, handler, disabled = false) {
        const input = el('select', '', { disabled });
        options.forEach(([key, label]) => input.add(new Option(label, key)));
        input.value = current ?? '';
        input.onchange = () => handler(input.value);
        return input;
    }
    function drawDetails() {
        const content = $('[data-details]');
        const readonly = Boolean(editor.readonly);
        const name = el('input', '', { type: 'text', maxLength: 120, value: editor.name ?? '', disabled: readonly });
        name.oninput = () => { editor.name = name.value; editor.dirty = true; };
        const format = select(Object.keys(dimensions).map(k => [k, t(k)]), editor.design.format, key => {
            editor.design.format = key; changed();
        }, readonly);
        const background = el('input', '', { type: 'color', value: editor.design.background, disabled: readonly });
        background.onchange = () => { editor.design.background = background.value; changed(); };
        content.replaceChildren(labeled(t('name'), name), labeled(t('format'), format), labeled(t('background'), background));
        if (editor.mode === 'template' && editor.revision) {
            const version = el('input', '', { type: 'number', min: '1', max: editor.revision, value: editor.version ?? editor.revision });
            content.append(labeled(t('version'), box(version, button('load_version', () => perform(async () => {
                const data = await request('template', { id: editor.id, version: version.value });
                editor.version = version.value;
                editor.design = data.design; editor.active = -1; changed(); drawDetails();
            }, true)))));
        }
        if (editor.mode === 'post') {
            const templates = select([['', t('no_template')], ...editor.templates.map(item => [item.id, item.name])], editor.template, id => perform(async () => {
                editor.design = id ? (await request('template', { id })).design : defaultDesign(editor.kind, config.labels);
                editor.template = id; editor.active = -1; changed(); drawDetails();
            }, true));
            const caption = el('textarea', '', { maxLength: 2200, rows: 2, value: editor.caption });
            caption.oninput = () => { editor.caption = caption.value; editor.dirty = true; };
            const controls = channels.map(channel => {
                const input = el('input', '', { type: 'checkbox', checked: editor.targets.includes(channel) });
                input.onchange = () => {
                    editor.targets = input.checked ? [...editor.targets, channel] : editor.targets.filter(item => item !== channel);
                    editor.dirty = true;
                };
                const label = el('label'); label.append(input, el('span', t(channel))); return label;
            });
            content.append(labeled(t('use_template'), templates), labeled(t('targets'), box(...controls)), labeled(t('caption'), caption));
        }
    }
    function drawPanels() {
        const current = editor;
        if (!current) return;
        const tools = $('[data-tools]'), props = $('[data-properties]'), footer = $('[data-editor-footer]');
        const readonly = Boolean(current.readonly), layer = current.design.layers[current.active], disabled = readonly || Boolean(layer?.locked);
        const media = select([['', t('choose')], ...current.media.map(m => [m.id, m.name])], current.mediaId, id => {
            current.mediaId = id; drawPanels();
        }, readonly);
        const field = select(tokens.map(key => [key, t(key)]), current.field ?? 'club', key => { current.field = key; }, readonly);
        const layers = el('div', '', { className: 'cu-social-layers' });
        current.design.layers.forEach((item, index) => {
            const label = item.type === 'field' ? t(item.field) : item.type === 'text' ? item.text : t(item.type);
            layers.prepend(button('select', () => {
                current.active = index; drawPanels(); redraw().catch(failImage);
            }, { pressed: index === current.active, text: (index + 1) + ' · ' + (label || t(item.type)).slice(0, 35) }));
        });
        tools.replaceChildren(el('h3', t('media')), media,
            box(button('image', () => addLayer('image'), { disabled: readonly || !current.mediaId }),
                button('background_image', () => addLayer('image', true), { disabled: readonly || !current.mediaId })),
            ...(perm('upload') && !readonly ? [uploadControl(true)] : []), el('h3', t('layers')),
            box(button('text', () => addLayer('text'), { disabled: readonly }), button('rect', () => addLayer('rect'), { disabled: readonly })),
            field, button('add', () => addLayer('field'), { disabled: readonly }), layers);
        props.replaceChildren(el('h3', t('properties')));
        if (layer) {
            const number = (key, min, max, step = 1) => {
                const input = el('input', '', { type: 'number', min, max, step, disabled, value: Math.round(layer[key] ?? (key === 'size' ? 50 : 0)) });
                input.onchange = () => { layer[key] = limit(Number(input.value), min, max); changed(); };
                return labeled(t(key), input);
            };
            const toggle = (key, value, fn, off = disabled) => {
                const input = el('input', '', { type: 'checkbox', checked: Boolean(value), disabled: off });
                input.onchange = () => { fn(input.checked); changed(); };
                const label = el('label'); label.append(input, el('span', t(key))); return label;
            };
            const move = delta => {
                const index = current.active, next = index + delta;
                if (next < 0 || next >= current.design.layers.length) return;
                [current.design.layers[index], current.design.layers[next]] = [current.design.layers[next], current.design.layers[index]];
                current.active = next; changed();
            };
            props.append(toggle('lock', layer.locked, value => { layer.locked = value; }, readonly),
                box(button('forward', () => move(1), { disabled, icon: true }),
                    button('backward', () => move(-1), { disabled, icon: true }),
                    button('remove', () => { current.design.layers.splice(current.active, 1); current.active = -1; changed(); }, { disabled, icon: true })));
            const grid = el('div', '', { className: 'cu-social-grid' });
            grid.append(number('x', -2160, 2160), number('y', -2700, 2700), number('w', 10, 2160), number('h', 10, 2700), number('angle', -360, 360));
            props.append(grid);
            if (layer.type !== 'image') {
                const fill = el('input', '', { type: 'color', value: layer.fill ?? '#ffffff', disabled });
                fill.onchange = () => { layer.fill = fill.value; changed(); };
                props.append(labeled(t('fill'), fill));
            }
            if (layer.type === 'text' || layer.type === 'field') {
                if (layer.type === 'text') {
                    const input = el('textarea', '', { rows: 2, value: layer.text ?? '', maxLength: 1000, disabled });
                    input.onchange = () => { layer.text = input.value; changed(); };
                    props.append(labeled(t('text'), input));
                } else props.append(labeled(t('field'), select(tokens.map(key => [key, t(key)]), layer.field, value => { layer.field = value; changed(); }, disabled)));
                props.append(number('size', 12, 180), toggle('bold', layer.bold, value => { layer.bold = value; }),
                    labeled(t('align'), select(['left', 'center', 'right'].map(key => [key, t(key)]), layer.align ?? 'left', value => { layer.align = value; changed(); }, disabled)));
            }
            if (layer.type === 'image') props.append(toggle('flipX', layer.flipX, value => { layer.flipX = value; }),
                box(button('crop', () => cropForm(layer), { disabled, icon: true }),
                    button('replace', () => { layer.media = current.mediaId; delete layer.crop; changed(); }, { disabled: disabled || !current.mediaId })));
        } else props.append(el('p', t('choose_layer')));
        footer.replaceChildren(button('undo', () => history(-1), { icon: true, disabled: readonly || current.cursor === 0 }),
            button('redo', () => history(1), { icon: true, disabled: readonly || current.cursor === current.history.length - 1 }),
            el('span', '', { className: 'cu-social-spacer' }));
        if (!readonly) footer.append(button(current.mode === 'template' ? 'save' : 'save_post', () => perform(saveEditor, true), { primary: true }));
    }
    async function cropForm(layer) {
        const img = await imageSource(layer.media).catch(() => null);
        if (!img) return failImage();
        const aspect = layer.w / layer.h, iw = img.naturalWidth, ih = img.naturalHeight;
        const cw = Math.min(iw, ih * aspect), ch = Math.min(ih, iw / aspect);
        const automatic = { x: (iw - cw) / 2 / iw, y: (ih - ch) / 2 / ih, w: cw / iw, h: ch / ih };
        const content = el('form');
        const sample = el('img', '', { src: img.src, alt: '' });
        const outline = el('div');
        const preview = el('div');
        preview.style.cssText = 'position:relative;display:table;margin:.6rem auto;max-width:100%;touch-action:none';
        sample.style.cssText = 'display:block;max-width:min(100%, 32rem);max-height:42vh;user-select:none;pointer-events:none';
        outline.style.cssText = 'position:absolute;border:2px solid #78e0c5;box-shadow:0 0 0 1px #182335;pointer-events:none';
        preview.append(sample, outline);
        const values = clone(layer.crop ?? automatic);
        const inputs = {};
        const sync = () => {
            for (const key of ['x', 'y', 'w', 'h']) inputs[key].value = Math.round(values[key] * 10000) / 100;
            Object.assign(outline.style, { left: values.x * 100 + '%', top: values.y * 100 + '%', width: values.w * 100 + '%', height: values.h * 100 + '%' });
        };
        const controls = ['x', 'y', 'w', 'h'].map(key => {
            const input = inputs[key] = el('input', '', { type: 'number', min: key === 'w' || key === 'h' ? 1 : 0, max: 100, step: .01 });
            input.onchange = () => {
                values[key] = limit(Number(input.value) / 100, key === 'w' || key === 'h' ? .01 : 0, 1);
                values.x = Math.min(values.x, 1 - values.w); values.y = Math.min(values.y, 1 - values.h); sync();
            };
            return labeled(t('crop_' + key), input);
        });
        let drag;
        preview.onpointerdown = event => {
            const rect = preview.getBoundingClientRect();
            drag = { x: event.clientX, y: event.clientY, left: values.x, top: values.y, rect };
            preview.setPointerCapture(event.pointerId);
        };
        preview.onpointermove = event => {
            if (!drag) return;
            values.x = limit(drag.left + (event.clientX - drag.x) / drag.rect.width, 0, 1 - values.w);
            values.y = limit(drag.top + (event.clientY - drag.y) / drag.rect.height, 0, 1 - values.h); sync();
        };
        preview.onpointerup = preview.onpointercancel = () => { drag = null; };
        content.append(preview, box(...controls), box(button('reset_crop', () => { Object.assign(values, automatic); sync(); }),
            el('button', t('crop'), { type: 'submit' })));
        sync();
        const modal = auxiliary(t('crop'), content);
        content.onsubmit = event => { event.preventDefault(); layer.crop = values; modal.close(); changed(); };
    }
    async function saveEditor() {
        if (!editor?.name?.trim()) throw new Error(t('invalid'));
        const current = editor;
        if (current.mode === 'template') {
            await request('template-save', {}, { id: current.id, revision: current.revision, name: current.name.trim(), design: current.design });
            tab = 'templates';
        } else {
            if (!current.targets.length) throw new Error(t('invalid'));
            if (!await redraw()) throw new Error(t('image_error'));
            const zoom = canvas.getZoom();
            // The export must never inherit rounding from the responsive preview.
            const output = canvas.toCanvasElement(1 / zoom, { width: (1080 + .01) * zoom,
                height: (dimensions[current.design.format] + .01) * zoom });
            const image = await new Promise(resolve => output.toBlob(resolve, 'image/jpeg', .93));
            if (!image) throw new Error(t('image_error'));
            const form = new FormData();
            form.append('metadata', JSON.stringify({
                id: current.id, revision: current.revision, kind: current.kind, title: current.name.trim(), caption: current.caption,
                targets: current.targets, design: current.design, date: current.snapshot.date ?? current.date,
                fixtures: (current.snapshot.fixtures ?? []).map(f => f.id), seen: current.snapshot,
            }));
            form.append('image', image, 'post.jpg');
            await request('post-save', {}, form);
            tab = 'posts';
        }
        pages[tab] = 1; archive.checked = false; current.dirty = false; dialog.close(); await load(); alert(message, t('saved'));
    }
    function close() {
        if (!busy && (!editor?.dirty || window.confirm(t('discard')))) dialog.close();
    }
    $('[data-close]').onclick = close;
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    dialog.addEventListener('close', () => { editor = null; generation++; });
    root.querySelectorAll('[data-tab]').forEach(control => { control.onclick = () => {
        tab = control.dataset.tab; archive.checked = false; selection.clear(); load();
    }; });
    date.onchange = () => { selection.clear(); load(); };
    archive.onchange = () => { pages[tab] = 1; load(); };
    load();
}

function boot() {
    document.querySelectorAll('[data-social-studio]:not([data-mounted])').forEach(root => {
        root.dataset.mounted = ''; mount(root);
    });
}
if (typeof document !== 'undefined') {
    boot();
    document.addEventListener('livewire:navigated', boot);
}
