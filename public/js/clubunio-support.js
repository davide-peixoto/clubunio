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
