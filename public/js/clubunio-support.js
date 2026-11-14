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
        turn.textContent = (data.ai ? form.dataset.ai + ': ' : '') + data.text;
        output.append(turn);
        while (output.children.length > 8) output.firstElementChild.remove();
        form.elements.body.value = '';
        if (data.article) {
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
