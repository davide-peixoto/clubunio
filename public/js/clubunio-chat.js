const make = (tag, text, className) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
};
const uuid = () => crypto.randomUUID();

export class ClubUnioChat {
    constructor(root) {
        this.root = root; this.config = JSON.parse(root.dataset.config); this.labels = JSON.parse(root.dataset.labels);
        this.cap = this.config.capabilities; this.chats = []; this.messages = new Map(); this.drafts = new Map();
        this.chat = null; this.generation = 0; this.cursor = 0; this.older = false; this.busy = false; this.polling = false;
        this.stopped = false; this.timer = null; this.lastList = 0; this.controllers = new Set();
        this.loading = false; this.dialogVersion = 0; this.listVersion = 0; this.objectUrls = new Set(); this.pollChoices = new Map();
        this.dialog = root.querySelector('[data-dialog]'); this.text = root.querySelector('[data-text]');
        this.form = root.querySelector('[data-composer]'); this.error = root.querySelector('[data-error]');
        this.history = root.querySelector('[data-history]'); this.status = root.querySelector('[data-status]');
        root.addEventListener('click', event => {
            const action = event.target.closest('[data-action]');
            if (action && root.contains(action)) this.guard(() => this.action(action.dataset.action));
        });
        this.form.addEventListener('submit', event => { event.preventDefault(); this.guard(() => this.send()); });
        root.querySelector('[data-search]').addEventListener('input', () => this.renderList());
        this.text.addEventListener('input', () => { if (this.chat) this.draft().text = this.text.value; });
        root.querySelector('[data-file]').addEventListener('change', event => this.guard(() => this.upload(event.target.files[0])));
        root.querySelector('[data-camera]').addEventListener('change', event => this.guard(() => this.upload(event.target.files[0])));
        this.dialog.addEventListener('close', () => { if (!this.dialog.open) { this.dialogVersion++; this.clearPreview(); this.dialogReturn?.focus(); } });
        this.onVisibility = () => { if (!document.hidden && !this.stopped) this.guard(() => this.poll()); };
        document.addEventListener('visibilitychange', this.onVisibility);
        this.onUnload = event => { if ([...this.drafts.values()].some(d => d.text || d.pending || d.attachment || d.upload || d.poll || d.event)) { event.preventDefault(); event.returnValue = ''; } };
        window.addEventListener('beforeunload', this.onUnload);
        this.onNavigate = event => { if ([...this.drafts.values()].some(d => d.text || d.pending || d.attachment || d.upload || d.event || d.poll) && !window.confirm(this.t('Entwurf verwerfen?'))) event.preventDefault(); };
        document.addEventListener('livewire:navigate', this.onNavigate);
        this.guard(async () => { try { await this.refreshList(); } finally { this.schedule(); } });
    }
    t(key, params = {}) { let value = this.labels[key] || key; for (const [k, v] of Object.entries(params)) value = value.replaceAll(':' + k, String(v)); return value; }
    draft() {
        if (!this.drafts.has(this.chat.id)) this.drafts.set(this.chat.id, {text: '', kind: 'text', attachment: null, poll: null, event: null, pending: null});
        return this.drafts.get(this.chat.id);
    }
    destroy() {
        this.stopped = true; this.generation++; this.dialogVersion++; clearTimeout(this.timer); for (const controller of this.controllers) controller.abort();
        for (const url of this.objectUrls) URL.revokeObjectURL(url); this.objectUrls.clear();
        this.dialog.close(); this.drafts.clear(); this.pollChoices.clear();
        document.removeEventListener('visibilitychange', this.onVisibility); window.removeEventListener('beforeunload', this.onUnload);
        document.removeEventListener('livewire:navigate', this.onNavigate);
    }
    current(generation) { return !this.stopped && generation === this.generation; }
    dialogCurrent(version) { return !this.stopped && this.dialog.open && version === this.dialogVersion; }
    async exclusive(callback) {
        if (this.busy || this.loading || this.stopped) return;
        this.busy = true; this.generation++; this.toolbar();
        try { return await callback(); } finally { this.busy = false; this.toolbar(); }
    }
    bindForm(form, callback) {
        const version = this.dialogVersion;
        form.addEventListener('submit', event => { event.preventDefault();
            if (!this.dialogCurrent(version)) return;
            this.guard(() => this.exclusive(async () => {
                const submit = form.querySelector('[type=submit]'); if (submit) submit.disabled = true;
                try { await callback(); } finally { if (submit) submit.disabled = false; }
            }));
        });
    }
    async guard(callback) {
        try { return await callback(); }
        catch (error) {
            if (this.stopped || error.name === 'AbortError') return;
            this.error.hidden = false;
            const code = error.status;
            const text = [401, 403, 419].includes(code) ? 'Der Zugriff hat sich geändert. Bitte die Seite neu laden.'
                : code === 409 ? 'Die Daten wurden geändert. Bitte aktualisieren und erneut prüfen.'
                : code === 503 ? 'Die Funktion ist derzeit nicht verfügbar.'
                : code === 422 ? 'Bitte prüfen Sie Ihre Eingaben.' : 'Verbindung unterbrochen. Ihr Entwurf bleibt erhalten.';
            this.error.textContent = this.t(text);
            if (this.dialog.open) {
                let alert = this.dialog.querySelector('[data-dialog-error]');
                if (!alert) { alert = make('p', undefined, 'cu-chat-alert'); alert.dataset.dialogError = ''; alert.setAttribute('role', 'alert'); this.root.querySelector('[data-dialog-body]').prepend(alert); }
                alert.textContent = this.t(text);
            }
            if ([401, 403, 419].includes(code)) {
                this.destroy(); this.dialog.close(); this.form.hidden = true;
                this.messages.clear(); this.chats = []; this.renderMessages(); this.renderList();
                this.root.querySelector('[data-title]').textContent = this.t('Chats');
            }
        }
    }
    async api(operation, input = {}, post = false, file = null) {
        if (this.stopped) throw Object.assign(new Error('stopped'), {status: 419});
        const controller = new AbortController(); this.controllers.add(controller);
        const timeout = setTimeout(() => controller.abort(), operation === 'ai' ? 100000 : 35000);
        const options = {method: post ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
            headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''}};
        const url = new URL(this.config.base + '/' + operation, window.location.origin);
        if (file) {
            const body = new FormData(); for (const [k, v] of Object.entries(input)) body.append(k, v);
            for (const [k, v] of Object.entries(this.config.context)) body.append(`context[${k}]`, v);
            body.append('file', file); options.body = body;
        } else if (post) { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify({...input, context: this.config.context}); }
        else {
            for (const [k, v] of Object.entries(input)) url.searchParams.set(k, v);
            for (const [k, v] of Object.entries(this.config.context)) url.searchParams.set(`context[${k}]`, v);
        }
        try {
            const response = await fetch(url, options);
            if (this.stopped) throw new DOMException('Stopped', 'AbortError');
            if (response.redirected) throw Object.assign(new Error('session'), {status: 419});
            if (!response.ok) throw Object.assign(new Error('request'), {status: response.status});
            if (operation !== 'download' && !response.headers.get('content-type')?.includes('application/json')) throw Object.assign(new Error('session'), {status: 419});
            return await (operation === 'download' ? response.blob() : response.json());
        } catch (error) {
            if (error.name === 'AbortError' && !this.stopped) throw new Error('timeout');
            throw error;
        } finally { clearTimeout(timeout); this.controllers.delete(controller); }
    }
    async refreshList() {
        const version = ++this.listVersion, result = await this.api('list');
        if (this.stopped || version !== this.listVersion) return;
        this.chats = result.data.conversations; this.cap = result.data.capabilities;
        this.lastList = Date.now(); this.renderList(); this.toolbar();
    }
    title(chat) {
        if (chat.kind === 'announcements') return this.t('Vereinsankündigungen');
        if (chat.kind === 'event') return this.t('Terminbesprechung');
        if (chat.kind === 'staff' && chat.title.startsWith('Trainer · ')) return this.t('Trainer') + chat.title.slice(7);
        return chat.title;
    }
    renderList() {
        const list = this.root.querySelector('[data-conversations]'); list.replaceChildren();
        const term = this.root.querySelector('[data-search]').value.toLocaleLowerCase();
        for (const chat of this.chats.filter(c => this.title(c).toLocaleLowerCase().includes(term))) {
            const button = make('button', undefined, 'cu-chat-thread'); button.type = 'button'; button.setAttribute('aria-current', String(this.chat?.id === chat.id));
            const name = make('span', this.title(chat), 'cu-chat-thread-name');
            if (chat.last_message) name.append(make('small', chat.last_message.text, 'cu-chat-last-message'));
            if (chat.muted) name.append(make('small', this.t('Stummschalten')));
            button.append(name); if (chat.unread) button.append(make('span', String(chat.unread), 'cu-chat-count'));
            button.addEventListener('click', () => this.guard(() => this.select(chat.id))); list.append(button);
        }
        if (!list.children.length) list.append(make('p', this.t('Keine Unterhaltungen'), 'cu-chat-empty'));
        this.root.querySelector('[data-action=contacts]').hidden = !this.cap.send;
    }
    async select(id) {
        if (this.busy || this.stopped) return;
        const generation = ++this.generation;
        this.dialog.close(); this.dialogVersion++; this.loading = true; this.chat = null; this.older = false; this.cursor = 0;
        this.messages.clear(); this.renderMessages(); this.toolbar(); this.status.textContent = this.t('Laden');
        try {
        const conversation = await this.api('conversation', {chat_id: id});
        if (!this.current(generation)) return;
        this.chat = conversation.data; this.messages.clear(); this.error.hidden = true;
        this.root.classList.add('has-conversation'); this.root.querySelector('[data-title]').textContent = this.title(this.chat);
        this.text.value = this.draft().text; this.renderMessages(); this.toolbar(); this.renderList();
        const page = await this.api('messages', {chat_id: id});
        if (!this.current(generation)) return;
        this.merge(page.data); this.cursor = page.meta.change_cursor; this.older = page.meta.has_more; this.joinedAfter = page.meta.joined_after;
        this.renderMessages(); this.history.scrollTop = this.history.scrollHeight;
        this.loading = false; this.toolbar(); await this.markRead();
        } finally { if (this.current(generation)) { this.loading = false; this.toolbar(); } }
    }
    merge(rows) {
        for (const row of rows) if (row && row.conversation_id === this.chat?.id && (!this.messages.has(row.id) || row.revision >= this.messages.get(row.id).revision)) this.messages.set(row.id, row);
    }
    async markRead() {
        if (!this.chat || document.hidden || !this.messages.size || this.stopped) return;
        const id = this.chat.id, generation = this.generation;
        await this.api('read', {chat_id: id, message_id: Math.max(...this.messages.keys())}, true);
        if (!this.current(generation)) return;
        const entry = this.chats.find(c => c.id === id); if (entry) entry.unread = 0; this.renderList();
    }
    schedule() { clearTimeout(this.timer); if (!this.stopped) this.timer = setTimeout(() => this.guard(async () => { try { await this.poll(); } finally { this.schedule(); } }), 12000); }
    async poll() {
        if (this.polling || this.busy || this.loading || this.loadingOlder || document.hidden || this.stopped || !this.root.isConnected) return;
        this.polling = true;
        try {
            if (this.chat) {
                const id = this.chat.id, generation = this.generation;
                for (let page = 0; page < 5; page++) {
                    const delta = await this.api('changes', {chat_id: id, after: this.cursor});
                    if (!this.current(generation)) return;
                    if (delta.meta.reset_required || delta.meta.joined_after !== this.joinedAfter) { await this.select(id); break; }
                    const nearEnd = this.history.scrollHeight - this.history.scrollTop - this.history.clientHeight < 120;
                    this.cursor = delta.meta.cursor; this.chat.can_write = delta.meta.can_write; this.chat.can_moderate = delta.meta.can_moderate;
                    this.merge(delta.data.map(change => change.message));
                    this.renderMessages(); if (nearEnd) this.history.scrollTop = this.history.scrollHeight;
                    this.toolbar();
                    if (!delta.meta.has_more) break;
                }
                await this.markRead();
            }
            if (Date.now() - this.lastList > 60000) await this.refreshList();
        } finally { this.polling = false; }
    }
    iconButton(icon, label, callback) {
        const button = make('button'); button.type = 'button'; button.title = this.t(label); button.setAttribute('aria-label', this.t(label));
        const template = this.root.querySelector(`[data-icon="${icon}"]`);
        if (template) button.append(template.content.cloneNode(true)); else button.textContent = this.t(label);
        button.addEventListener('click', () => { if (!this.busy && !this.stopped) this.guard(callback); }); return button;
    }
    renderMessages() {
        const list = this.root.querySelector('[data-messages]'); list.replaceChildren();
        this.root.querySelector('[data-action=older]').hidden = !this.older;
        for (const row of [...this.messages.values()].sort((a, b) => a.id - b.id)) {
            const item = make('article', undefined, 'cu-chat-message' + (row.author_id === this.config.actorId ? ' own' : ''));
            item.dataset.messageId = row.id;
            const header = make('header'); header.append(make('strong', row.author || row.author_name || ''), make('time', new Date(row.created_at).toLocaleString(this.config.locale)));
            item.append(header, make('p', row.deleted ? this.t('Nachricht entfernt') : row.text));
            if (!row.deleted && row.attachment) {
                const file = make('div', row.attachment.name, 'cu-chat-file');
                if (this.cap.download) file.append(this.iconButton('preview', 'Anhang ansehen', () => this.previewAttachment(row.attachment)), this.iconButton('download', 'Anhang herunterladen', () => this.download(row.attachment)));
                item.append(file);
            }
            if (!row.deleted && row.poll) this.renderPoll(item, row);
            if (!row.deleted && row.event_id) { const b = make('button', this.t('Terminbesprechung')); b.type = 'button'; b.addEventListener('click', () => this.guard(async () => { const r = await this.api('event-chat', {event_id: row.event_id}, true); await this.refreshList(); await this.select(r.data.id); })); if (this.cap.send) item.append(b); }
            const actions = make('div', undefined, 'cu-chat-actions');
            for (const reaction of row.reactions || []) {
                const b = make('button', `${reaction.emoji} ${reaction.count}`, 'cu-chat-reaction'); b.type = 'button'; b.setAttribute('aria-pressed', String(reaction.mine));
                b.addEventListener('click', () => this.guard(() => this.reactionPeople(row))); actions.append(b);
            }
            if (!row.deleted && this.cap.send && this.chat?.can_write) {
                if (row.can_react) actions.append(this.iconButton('reactions', 'Reaktionen', () => this.reactions(row)));
                if (row.can_edit) actions.append(this.iconButton('edit', 'Bearbeiten', () => this.edit(row)));
                if (row.can_delete) actions.append(this.iconButton('delete', 'Entfernen', () => this.confirm('Nachricht entfernen?', async () => this.updateMessage('delete', row))));
                if (this.chat?.kind !== 'direct') actions.append(this.iconButton('report', 'Melden', () => this.report(row)));
                if (this.cap.ai_enabled && this.chat?.chat_preferences?.translation_enabled) actions.append(this.iconButton('translate', 'Übersetzen', () => this.ai(row)));
            }
            item.append(actions); list.append(item);
        }
    }
    renderPoll(parent, row) {
        const poll = row.poll; const section = make('form', undefined, 'cu-chat-poll');
        const closed = new Date(poll.closes_at) <= new Date();
        for (const option of poll.options) {
            const label = make('label'); const input = make('input'); input.type = poll.multiple ? 'checkbox' : 'radio'; input.name = `poll-${poll.id}`; input.value = option.id;
            input.checked = (this.pollChoices.get(poll.id) || poll.my_choices).includes(option.id); input.disabled = closed || !this.cap.send || !this.chat?.can_write;
            input.addEventListener('change', () => this.pollChoices.set(poll.id, [...section.querySelectorAll('input:checked')].map(i => i.value)));
            label.append(input, make('span', `${option.text} (${option.count})`)); section.append(label);
        }
        section.append(make('p', closed ? this.t('Umfrage beendet') : new Date(poll.closes_at).toLocaleString(this.config.locale), 'cu-chat-meta'));
        if (!closed && this.cap.send && this.chat?.can_write) section.append(this.submit('Abstimmen'));
        section.addEventListener('submit', event => { event.preventDefault(); this.guard(() => this.exclusive(async () => {
            const result = await this.api('vote', {poll_id: poll.id, choices: [...section.querySelectorAll('input:checked')].map(i => i.value)}, true);
            this.pollChoices.delete(poll.id);
            if (!this.stopped && this.chat?.id === row.conversation_id) { this.messages.set(row.id, {...this.messages.get(row.id), poll: result.data}); this.renderMessages(); }
        })); }); parent.append(section);
    }
    toolbar() {
        const chat = this.chat, draft = chat ? this.draft() : null;
        this.form.hidden = !chat || !chat.can_write || !this.cap.send || this.stopped || this.loading;
        this.text.disabled = this.busy || Boolean(draft?.pending);
        this.root.querySelector('[data-send]').disabled = this.busy || Boolean(draft?.upload || (draft?.attachment && draft.attachment.state !== 'clean'));
        this.root.querySelector('[data-send]').title = this.t(draft?.pending ? 'Erneut versuchen' : 'Senden');
        this.root.querySelector('[data-send]').setAttribute('aria-label', this.root.querySelector('[data-send]').title);
        for (const [action, show] of Object.entries({mute: chat && this.cap.send, reports: chat?.can_moderate && this.cap.moderate,
            block: chat?.other_account_id && this.cap.send, attachment: this.cap.upload && this.cap.uploads_enabled, camera: this.cap.upload && this.cap.uploads_enabled,
            poll: chat?.can_poll && this.cap.poll, event: chat?.can_link_event, ai: this.cap.ai_enabled && this.cap.send})) {
            this.root.querySelector(`[data-action=${action}]`).hidden = !show;
        }
        const mute = this.root.querySelector('[data-action=mute]'); mute.title = this.t(chat?.muted ? 'Stummschaltung aufheben' : 'Stummschalten'); mute.setAttribute('aria-label', mute.title);
        const selection = this.root.querySelector('[data-selection]'); selection.replaceChildren();
        const attachment = draft?.attachment;
        if (attachment || draft?.upload) {
            selection.append(make('span', attachment?.name || draft.upload.file.name));
            if (attachment?.state === 'clean' && draft.file) selection.append(this.iconButton('preview', 'Anhang ansehen', () => this.previewAttachment(attachment, draft.file)));
            if (!this.busy && !draft.pending && (!attachment || attachment.state === 'quarantine')) selection.append(this.iconButton('retry', 'Erneut versuchen', () => this.retryUpload()));
            if (!this.busy && !draft.pending) selection.append(this.iconButton('delete', 'Auswahl entfernen', () => this.removeSelection()));
        } else if (draft?.event) selection.append(make('span', `${draft.event.title} · ${new Date(draft.event.starts_at).toLocaleString(this.config.locale)}`));
        else if (draft?.poll) selection.append(make('span', this.t('Umfrage')));
        selection.hidden = !selection.childNodes.length;
        this.status.textContent = this.loading ? this.t('Laden') : chat && !chat.can_write ? this.t('Nur Lesen') : draft?.pending ? this.t('Nachricht konnte nicht gesendet werden. Erneut versuchen verwendet dieselbe Nachrichten-ID.')
            : attachment && attachment.state !== 'clean' ? this.t(attachment.state === 'quarantine' ? 'Anhang wird geprüft' : 'Anhang abgelehnt') : '';
        for (const button of this.root.querySelectorAll('[data-action]')) button.disabled = this.busy && button.dataset.action !== 'close-dialog';
    }
    async action(action) {
        if (action === 'close-dialog') { this.dialog.close(); return; }
        if (this.busy || this.stopped) return;
        if (action === 'back') { this.generation++; this.loading = false; this.chat = null; this.messages.clear(); this.older = false; this.root.classList.remove('has-conversation'); this.renderMessages(); this.toolbar(); this.renderList(); return; }
        if (action === 'contacts') return this.contacts();
        if (action === 'settings') return this.settings();
        if (action === 'refresh') { await this.refreshList(); if (this.chat) await this.select(this.chat.id); return; }
        if (!this.chat || this.loading) return;
        if (['attachment', 'camera', 'poll', 'event', 'ai', 'discard'].includes(action) && this.draft().pending) {
            this.error.textContent = this.t('Bitte zuerst den ausstehenden Versand klären.'); this.error.hidden = false; return;
        }
        if (action === 'older') {
            if (this.loadingOlder || !this.messages.size) return;
            const generation = ++this.generation, height = this.history.scrollHeight; this.loadingOlder = true;
            try {
                const result = await this.api('messages', {chat_id: this.chat.id, before: Math.min(...this.messages.keys())});
                if (!this.current(generation)) return;
                if (result.meta.joined_after !== this.joinedAfter) { await this.select(this.chat.id); return; }
                this.merge(result.data); this.older = result.meta.has_more; this.renderMessages(); this.history.scrollTop += this.history.scrollHeight - height;
            } finally { this.loadingOlder = false; }
        } else if (action === 'mute') {
            await this.exclusive(async () => { const r = await this.api('mute', {chat_id: this.chat.id, muted: !this.chat.muted}, true); if (!this.stopped) { this.chat.muted = r.data.muted; this.toolbar(); await this.refreshList(); } });
        } else if (action === 'attachment') this.root.querySelector('[data-file]').click();
        else if (action === 'camera') this.root.querySelector('[data-camera]').click();
        else if (action === 'poll') this.pollEditor();
        else if (action === 'event') await this.events();
        else if (action === 'reports') await this.reports();
        else if (action === 'ai') this.ai();
        else if (action === 'block') this.confirm('Blockieren', async () => { await this.api('block', {account_id: this.chat.other_account_id, blocked: true}, true); this.chat = null; this.messages.clear(); this.renderMessages(); this.toolbar(); await this.refreshList(); });
        else if (action === 'discard') this.confirm('Entwurf verwerfen?', async () => { await this.clearAttachment(this.draft()); this.drafts.delete(this.chat.id); this.text.value = ''; this.toolbar(); });
    }
    open(title) {
        this.dialogVersion++; this.clearPreview();
        if (!this.dialog.open) this.dialogReturn = document.activeElement;
        this.root.querySelector('#cu-chat-dialog-title').textContent = this.t(title);
        const body = this.root.querySelector('[data-dialog-body]'); body.replaceChildren(); if (!this.dialog.open) this.dialog.showModal(); return body;
    }
    field(form, label, type = 'text', value = '') {
        const wrapper = make('label'); wrapper.append(make('span', this.t(label)));
        const input = make(type === 'textarea' ? 'textarea' : 'input'); if (type !== 'textarea') input.type = type;
        input.value = value; wrapper.append(input); form.append(wrapper); return input;
    }
    submit(label = 'Speichern') { const b = make('button', this.t(label), 'cu-chat-primary'); b.type = 'submit'; return b; }
    confirm(label, callback) { const body = this.open(label); const form = make('form'); form.append(this.submit()); this.bindForm(form, async () => { await callback(); this.dialog.close(); }); body.append(form); }
    async contacts() {
        const body = this.open('Neue Direktnachricht'); const form = make('form'); const search = this.field(form, 'Kontakt suchen', 'search'); search.maxLength = 120;
        form.append(this.submit('Kontakt suchen')); const results = make('ul'); body.append(form, results);
        const version = this.dialogVersion; let sequence = 0;
        const find = async () => { const request = ++sequence, r = await this.api('contacts', {q: search.value});
            if (!this.dialogCurrent(version) || request !== sequence) return; results.replaceChildren();
            for (const contact of r.data) { const li = make('li'), b = make('button', contact.name); b.type = 'button'; b.addEventListener('click', () => this.guard(async () => {
                if (!this.dialogCurrent(version)) return;
                const r = await this.exclusive(() => this.api('direct', {account_id: contact.id}, true));
                if (!r || !this.dialogCurrent(version)) return;
                this.dialog.close(); await this.refreshList(); await this.select(r.data.id);
            })); li.append(b); results.append(li); } if (!r.data.length) results.append(make('li', this.t('Keine Kontakte gefunden'))); };
        form.addEventListener('submit', e => { e.preventDefault(); this.guard(find); }); await find(); search.focus();
    }
    async send(confirmed = false) {
        if (!this.chat?.can_write || !this.cap.send || this.busy || this.loading || this.stopped) return;
        const draft = this.draft(), chat = this.chat;
        if (!draft.pending && (!this.text.value.trim() || draft.upload || (draft.attachment && draft.attachment.state !== 'clean'))) return;
        if (chat.kind === 'announcements' && !draft.pending && !confirmed) {
            const generation = this.generation, body = this.open('Empfängervorschau'), version = this.dialogVersion, text = this.text.value;
            body.append(make('p', this.t('Laden')));
            const preview = (await this.api('preview', {chat_id: chat.id})).data;
            if (!this.current(generation) || !this.dialogCurrent(version)) return;
            body.replaceChildren(make('p', this.title(chat)), make('p', text, 'cu-chat-ai-result'));
            body.append(make('p', this.t('Empfänger: :count', {count: preview.count}))); const list = make('ul');
            for (const recipient of preview.recipients) list.append(make('li', recipient.name)); body.append(list);
            const form = make('form'); form.append(this.submit('Veröffentlichen')); body.append(form);
            form.addEventListener('submit', e => { e.preventDefault();
                if (!this.current(generation) || !this.dialogCurrent(version) || this.text.value !== text) return;
                draft.fingerprint = preview.fingerprint; this.dialog.close(); this.guard(() => this.send(true));
            }); return;
        }
        if (!draft.pending) {
            draft.pending = {chat_id: chat.id, client_id: uuid(), text: this.text.value, kind: chat.kind === 'announcements' ? 'announcement' : draft.kind};
            if (draft.poll) draft.pending.poll = draft.poll;
            if (draft.event) draft.pending.event_id = draft.event.id;
            if (draft.attachment) draft.pending.attachment_id = draft.attachment.id;
            if (draft.fingerprint) draft.pending.recipient_fingerprint = draft.fingerprint;
        }
        this.busy = true; const generation = ++this.generation; this.error.hidden = true; this.toolbar();
        try {
            const r = await this.api('send', draft.pending, true);
            if (!this.current(generation)) return;
            this.merge([r.data]); this.drafts.delete(chat.id); this.text.value = ''; this.renderMessages(); this.history.scrollTop = this.history.scrollHeight;
            this.status.textContent = this.t('Nachricht erfolgreich gesendet');
        } catch (error) {
            // 422/409 are definitive failures. Network errors keep the frozen payload for safe retry.
            if ([409, 422].includes(error.status) && !draft.uncertain) { draft.pending = null; draft.fingerprint = null; }
            else draft.uncertain = true;
            throw error;
        } finally { this.busy = false; this.toolbar(); }
    }
    async upload(file) {
        if (!file || !this.chat || this.busy || this.draft().pending) return;
        try { await this.exclusive(async () => {
            if (file.size > 5242880 || !['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(file.type)) throw Object.assign(new Error('file'), {status: 422});
            const draft = this.draft(); await this.clearAttachment(draft);
            draft.upload = {file, client_id: uuid()}; draft.file = file; draft.kind = 'text'; draft.poll = null; draft.event = null;
            await this.stageAndScan(draft);
        }); } finally { this.root.querySelector('[data-file]').value = ''; this.root.querySelector('[data-camera]').value = ''; }
    }
    async stageAndScan(draft) {
        if (draft.upload) {
            const upload = draft.upload, staged = await this.api('upload', {chat_id: this.chat.id, client_id: upload.client_id}, true, upload.file);
            draft.attachment = {id: staged.data.id, name: upload.file.name, mime: upload.file.type, state: staged.data.state}; draft.upload = null;
        }
        if (draft.attachment?.state === 'quarantine') {
            const scanned = await this.api('scan', {attachment_id: draft.attachment.id}, true); draft.attachment.state = scanned.data.state;
        }
        if (draft.attachment?.state !== 'clean') throw Object.assign(new Error('scan'), {status: 503});
    }
    async retryUpload() { if (!this.chat || this.draft().pending) return; await this.exclusive(() => this.stageAndScan(this.draft())); }
    async clearAttachment(draft) {
        // Reconcile a timed-out stage with the same key before cancelling its private bytes.
        if (draft.upload) {
            const upload = draft.upload, r = await this.api('upload', {chat_id: this.chat.id, client_id: upload.client_id}, true, upload.file);
            draft.attachment = {id: r.data.id, name: upload.file.name, state: r.data.state}; draft.upload = null;
        }
        if (draft.attachment) await this.api('attachment-remove', {attachment_id: draft.attachment.id}, true);
        draft.attachment = null; draft.file = null;
    }
    async removeSelection() { if (!this.chat || this.draft().pending) return; await this.exclusive(() => this.clearAttachment(this.draft())); }
    clearPreview() {
        if (this.previewUrl) { URL.revokeObjectURL(this.previewUrl); this.objectUrls.delete(this.previewUrl); this.previewUrl = null; }
    }
    async previewAttachment(attachment, local = null) {
        const body = this.open('Anhang ansehen'), version = this.dialogVersion;
        body.append(make('p', attachment.name), make('p', this.t('Laden')));
        const blob = local || await this.api('download', {attachment_id: attachment.id});
        if (!this.dialogCurrent(version)) return;
        if (!['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(blob.type)) throw Object.assign(new Error('type'), {status: 422});
        const url = URL.createObjectURL(blob); this.previewUrl = url; this.objectUrls.add(url);
        const preview = make(blob.type === 'application/pdf' ? 'iframe' : 'img'); preview.className = 'cu-chat-preview';
        if (preview.tagName === 'IMG') preview.alt = attachment.name;
        else { preview.title = attachment.name; preview.referrerPolicy = 'no-referrer'; }
        preview.src = url; body.replaceChildren(make('p', attachment.name), preview);
        if (!local) body.append(this.iconButton('download', 'Anhang herunterladen', () => this.download(attachment)));
    }
    async download(attachment) {
        const blob = await this.api('download', {attachment_id: attachment.id}); const url = URL.createObjectURL(blob);
        if (this.stopped) { URL.revokeObjectURL(url); return; }
        this.objectUrls.add(url); const a = make('a'); a.href = url; a.download = attachment.name; document.body.append(a); a.click(); a.remove();
        setTimeout(() => { URL.revokeObjectURL(url); this.objectUrls.delete(url); }, 60000);
    }
    pollEditor() {
        const body = this.open('Umfrage'), form = make('form'), draft = this.draft();
        const question = this.field(form, 'Frage', 'textarea', draft.text); question.required = true; question.maxLength = 1000;
        const choices = this.field(form, 'Antwortmöglichkeiten', 'textarea', draft.poll?.options.join('\n') || ''); choices.required = true; choices.placeholder = this.t('Eine Antwort pro Zeile');
        const multiple = this.field(form, 'Mehrfachauswahl', 'checkbox'); multiple.checked = draft.poll?.multiple || false;
        const end = this.field(form, 'Abstimmungsende', 'datetime-local'); end.required = true;
        const date = new Date(draft.poll?.closes_at || Date.now() + 7 * 86400000); date.setMinutes(date.getMinutes() - date.getTimezoneOffset()); end.value = date.toISOString().slice(0, 16);
        form.append(this.submit()); body.append(form);
        this.bindForm(form, async () => {
            const options = choices.value.split('\n').map(v => v.trim()).filter(Boolean);
            if (options.length < 2 || options.length > 10 || new Set(options).size !== options.length || options.some(o => o.length > 200) || !(new Date(end.value) > new Date())) throw Object.assign(new Error('poll'), {status: 422});
            await this.clearAttachment(draft);
            draft.poll = {options, multiple: multiple.checked, closes_at: new Date(end.value).toISOString()}; draft.kind = 'poll'; draft.event = null; draft.attachment = null;
            draft.text = question.value; this.text.value = question.value; this.dialog.close(); this.toolbar();
        });
    }
    async events() {
        const body = this.open('Termin auswählen'), version = this.dialogVersion, draft = this.draft();
        body.append(make('p', this.t('Laden')));
        const r = await this.api('events', {team_season_id: this.chat.team_season_id});
        if (!this.dialogCurrent(version)) return; body.replaceChildren();
        const list = make('ul'); body.append(list);
        for (const event of r.data) { const li = make('li'), b = make('button', `${event.title} · ${new Date(event.starts_at).toLocaleString(this.config.locale)}`); b.type = 'button';
            b.addEventListener('click', () => { if (!this.dialogCurrent(version)) return; this.guard(() => this.exclusive(async () => {
                await this.clearAttachment(draft); draft.event = event; draft.kind = 'event'; draft.poll = null;
                this.dialog.close(); this.toolbar(); })); }); li.append(b); list.append(li); }
        if (!r.data.length) list.append(make('li', this.t('Keine Termine gefunden')));
    }
    async updateMessage(op, row, extra = {}) {
        const r = await this.api(op, {message_id: row.id, revision: row.revision, ...extra}, true);
        if (!this.stopped && this.chat?.id === row.conversation_id) { this.merge([r.data]); this.renderMessages(); }
    }
    edit(row) {
        const body = this.open('Bearbeiten'), form = make('form'), input = this.field(form, 'Nachricht', 'textarea', row.text); input.maxLength = 4000; input.required = true;
        form.append(this.submit()); body.append(form); this.bindForm(form, async () => { await this.updateMessage('edit', row, {text: input.value}); this.dialog.close(); });
    }
    report(row) {
        const body = this.open('Melden'), form = make('form'), reason = this.field(form, 'Grund', 'textarea'); reason.required = true; reason.maxLength = 1000;
        form.append(this.submit()); body.append(form); this.bindForm(form, async () => { await this.api('report', {message_id: row.id, reason: reason.value}, true); this.dialog.close(); });
    }
    reactions(row) {
        const body = this.open('Reaktionen');
        for (const emoji of ['👍', '❤️', '😂', '😮', '😢', '🙏']) { const b = make('button', emoji); b.type = 'button'; b.setAttribute('aria-label', emoji);
            b.addEventListener('click', () => this.guard(() => this.exclusive(async () => { await this.updateMessage('react', row, {emoji: row.reactions?.some(r => r.emoji === emoji && r.mine) ? null : emoji}); this.dialog.close(); }))); body.append(b); }
        const people = make('button', this.t('Personen')); people.type = 'button'; people.addEventListener('click', () => this.guard(() => this.reactionPeople(row))); body.append(people);
    }
    async reactionPeople(row) {
        const body = this.open('Personen'), list = make('ul'), more = make('button', this.t('Mehr anzeigen')); more.type = 'button'; body.append(list, more); let after = 0;
        const version = this.dialogVersion;
        const load = async () => { if (more.disabled) return; more.disabled = true;
            try { const r = await this.api('reactions', {message_id: row.id, after, limit: 30});
                if (!this.dialogCurrent(version)) return;
                for (const person of r.data) list.append(make('li', `${person.emoji} ${person.name}`)); after = r.meta.next_after; more.hidden = !r.meta.has_more;
                if (!list.children.length) list.append(make('li', this.t('Keine Reaktionen')));
            } finally { more.disabled = false; } };
        more.addEventListener('click', () => this.guard(load)); await load();
    }
    async reports() {
        const body = this.open('Moderation'), version = this.dialogVersion;
        body.append(make('p', this.t('Laden'))); const r = await this.api('reports', {chat_id: this.chat.id});
        if (!this.dialogCurrent(version)) return;
        const list = make('ul'); body.replaceChildren(list);
        for (const report of r.data) {
            const li = make('li'); li.append(make('p', report.message.text), make('p', report.reason));
            const resolve = make('button', this.t('Erledigen')); resolve.type = 'button'; resolve.addEventListener('click', () => this.guard(() => this.exclusive(async () => { await this.api('resolve', {report_id: report.id}, true); li.remove(); })));
            li.append(resolve);
            if (this.cap.send && report.message.can_delete) li.append(this.iconButton('delete', 'Entfernen', () => this.confirm('Nachricht entfernen?', async () => { await this.updateMessage('delete', report.message); await this.api('resolve', {report_id: report.id}, true); })));
            list.append(li);
        }
        if (!r.data.length) list.append(make('li', this.t('Keine offenen Meldungen')));
    }
    async settings() {
        const body = this.open('Einstellungen'), version = this.dialogVersion; body.append(make('p', this.t('Laden')));
        const preference = (await this.api('preferences')).data, blocks = (await this.api('blocks')).data;
        if (!this.dialogCurrent(version)) return; body.replaceChildren(); const form = make('form');
        const enabled = this.field(form, 'Übersetzung aktivieren', 'checkbox'); enabled.checked = preference.translation_enabled; enabled.disabled = !this.cap.send;
        const label = make('label', this.t('Zielsprache')), language = make('select');
        for (const [value, text] of Object.entries(this.config.languages || {})) {
            if (!preference.language_policy?.enabled?.includes(value)) continue;
            const o = make('option', text); o.value = value; language.append(o);
        }
        language.value = preference.target_language || this.config.locale; label.append(language); form.append(label); language.disabled = !this.cap.send;
        const allowed = this.cap.send && language.options.length > 0; enabled.disabled = language.disabled = !allowed;
        if (allowed) form.append(this.submit()); body.append(form, make('h3', this.t('Blockierte Kontakte')));
        const list = make('ul'); body.append(list);
        for (const contact of blocks) { const li = make('li', contact.name), b = make('button', this.t('Blockierung aufheben')); b.type = 'button'; b.disabled = !this.cap.send;
            b.addEventListener('click', () => this.guard(() => this.exclusive(async () => { await this.api('block', {account_id: contact.id, blocked: false}, true); li.remove(); await this.refreshList(); }))); li.append(b); list.append(li); }
        this.bindForm(form, async () => {
            if (!allowed || !language.value) return;
            const r = await this.api('preferences-save', {revision: preference.revision, translation_enabled: enabled.checked, target_language: language.value}, true);
            if (this.chat) this.chat.chat_preferences = r.data; this.dialog.close(); this.renderMessages();
        });
    }
    ai(row = null) {
        if (!this.chat || !this.cap.ai_enabled || this.draft().pending) return;
        const body = this.open(row ? 'Übersetzen' : 'KI-Textassistenz'), form = make('form'), operation = make('select');
        const version = this.dialogVersion, draft = this.draft(), source = row ? row.text : this.text.value;
        const language = row ? this.chat.chat_preferences?.target_language : this.config.locale;
        for (const [value, text] of row ? [['translate', 'Übersetzen']] : [['correct', 'Korrigieren'], ['friendly', 'Freundlicher formulieren'], ['shorten', 'Kürzen'], ['check', 'Prüfen']]) {
            const o = make('option', this.t(text)); o.value = value; operation.append(o);
        }
        operation.setAttribute('aria-label', this.t('KI-Textassistenz')); form.append(operation);
        const consent = this.field(form, 'Text zur Verarbeitung an den KI-Anbieter senden', 'checkbox'); consent.required = true;
        form.append(this.submit()); body.append(form); const result = make('div'); body.append(result);
        const requestKeys = new Map(); this.bindForm(form, async () => {
            if (!consent.checked || !Object.hasOwn(this.config.languages || {}, language)) return;
            const selected = operation.value;
            if (!requestKeys.has(selected)) requestKeys.set(selected, uuid());
            operation.disabled = true;
            try { const response = await this.api('ai', {request_key: requestKeys.get(selected), operation: selected,
                language, consent: true, ...(row ? {message_id: row.id} : {text: source})}, true);
                if (!this.dialogCurrent(version)) return;
                result.replaceChildren(make('p', response.data.text, 'cu-chat-ai-result'));
                if (!row) { const use = make('button', this.t('Text übernehmen')); use.type = 'button'; use.addEventListener('click', () => {
                    if (!this.dialogCurrent(version) || !this.chat || this.draft() !== draft || draft.pending || this.text.value !== source) return;
                    this.text.value = response.data.text; draft.text = response.data.text; this.dialog.close();
                }); result.append(use); }
            } finally { operation.disabled = false; }
        });
    }
}

function mount() {
    for (const root of document.querySelectorAll('[data-cu-chat]')) if (!root.clubunioChat) root.clubunioChat = new ClubUnioChat(root);
}
document.addEventListener('livewire:navigating', () => { for (const root of document.querySelectorAll('[data-cu-chat]')) root.clubunioChat?.destroy(); });
document.addEventListener('livewire:navigated', mount);
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
