if (/^#(?:faq-[a-z-]+|article-[0-9]+)$/.test(location.hash)) {
    const source = document.getElementById(location.hash.slice(1));
    if (source instanceof HTMLDetailsElement) source.open = true;
}

function appendSupportEmphasis(container, text) {
    let offset = 0;
    for (const match of text.matchAll(/(?<!\*)\*\*([^*\r\n]+)\*\*(?!\*)|(?<!\*)\*([^*\r\n]+)\*(?!\*)/g)) {
        container.append(text.slice(offset, match.index));
        const emphasis = document.createElement(match[1] ? 'strong' : 'em');
        emphasis.textContent = match[1] ?? match[2];
        container.append(emphasis);
        offset = match.index + match[0].length;
    }
    container.append(text.slice(offset));
}

document.querySelector('[data-support-guide]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    if (form.dataset.supportChat !== undefined) {
        await sendSupportChat(form);
        return;
    }
    const output = form.querySelector('output');
    const button = form.querySelector('button');
    button.disabled = true;
    try {
        const dataToSend = new FormData(form);
        if (form.dataset.topic) dataToSend.set('previous_topic', form.dataset.topic);
        const reply = await fetch(location.pathname, {
            method: 'POST', body: dataToSend, credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!reply.ok) throw new Error();
        const {data} = await reply.json();
        form.dataset.topic = data.source || '';
        const turn = document.createElement('p');
        if (data.ai) {
            turn.append(form.dataset.ai + ': ');
            appendSupportEmphasis(turn, data.text);
        } else {
            turn.textContent = data.text;
        }
        output.append(turn);
        while (output.children.length > 8) output.firstElementChild.remove();
        form.elements.body.value = '';
        if (form.dataset.knowledge && (data.article || data.source)) {
            const link = document.createElement('a');
            const target = new URL(form.dataset.knowledge);
            target.hash = data.article ? 'article-' + data.article.id : 'faq-' + data.source;
            link.href = target.href;
            link.textContent = form.dataset.source + (data.article ? ': ' + data.article.title : '');
            turn.append(' ', link);
        } else if (data.article) {
            const source = document.createElement('span');
            source.textContent = ' (' + form.dataset.source + ': ' + data.article.title + ')';
            turn.append(source);
        }
        if (data.source && document.getElementById('faq-' + data.source)) {
            const link = document.createElement('a');
            link.href = '#faq-' + data.source;
            link.textContent = form.dataset.source;
            link.onclick = () => { document.getElementById('faq-' + data.source).open = true; };
            turn.append(' ', link);
        }
    } catch {
        output.textContent = form.dataset.error;
    } finally {
        button.disabled = false;
    }
});

async function sendSupportChat(form) {
    if (form.dataset.sending === '1') return;
    const input = form.elements.body;
    const question = input.value.trim();
    if (!question) return;
    const log = form.querySelector('output');
    const button = form.querySelector('button');
    const message = (kind, author, text) => {
        const bubble = document.createElement('div');
        bubble.className = 'cs-chat-message cs-chat-' + kind;
        const heading = document.createElement('strong');
        heading.textContent = author;
        const content = document.createElement('p');
        content.textContent = text;
        bubble.append(heading, content);
        log.append(bubble);
        log.scrollTop = log.scrollHeight;
        return {bubble, content};
    };
    const dataToSend = new FormData(form);
    if (form.dataset.topic) dataToSend.set('previous_topic', form.dataset.topic);
    message('user', form.dataset.you, question);
    const pending = message('pending', form.dataset.assistant, form.dataset.pending);
    form.dataset.sending = '1';
    button.disabled = true;
    input.value = '';
    const abort = new AbortController();
    const timeout = setTimeout(() => abort.abort(), 60000);
    try {
        const reply = await fetch(location.pathname, {
            method: 'POST', body: dataToSend, credentials: 'same-origin',
            headers: {Accept: 'application/json'}, signal: abort.signal,
        });
        if (!reply.ok) throw new Error();
        const {data} = await reply.json();
        pending.content.textContent = '';
        if (data.ai) appendSupportEmphasis(pending.content, data.text);
        else pending.content.textContent = data.text;
        pending.bubble.className = 'cs-chat-message cs-chat-assistant';
        form.dataset.topic = data.source || '';
        if (data.article || data.source) {
            const target = new URL(form.dataset.knowledge, location.href);
            if (target.origin === location.origin) {
                target.hash = data.article ? 'article-' + data.article.id : 'faq-' + data.source;
                const source = document.createElement('a');
                source.href = target.href;
                source.textContent = form.dataset.source + (data.article ? ': ' + data.article.title : '');
                pending.bubble.append(source);
            }
        }
    } catch {
        pending.content.textContent = form.dataset.error;
        pending.bubble.className = 'cs-chat-message cs-chat-error';
        if (!input.value) input.value = question;
    } finally {
        clearTimeout(timeout);
        delete form.dataset.sending;
        button.disabled = false;
        log.scrollTop = log.scrollHeight;
        input.focus();
    }
}

const chatForm = document.querySelector('[data-support-chat]');
if (chatForm?.dataset.supportChat !== undefined) {
    chatForm.elements.body.addEventListener('keydown', event => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            chatForm.requestSubmit();
        }
    });
}
