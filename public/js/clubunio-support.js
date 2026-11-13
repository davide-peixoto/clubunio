document.querySelector('[data-support-guide]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const output = form.querySelector('output');
    const button = form.querySelector('button');
    button.disabled = true;
    output.replaceChildren();
    try {
        const reply = await fetch(location.pathname, {
            method: 'POST', body: new FormData(form), credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!reply.ok) throw new Error();
        const {data} = await reply.json();
        output.textContent = (data.ai ? form.dataset.ai + ': ' : '') + data.text;
        if (data.source && document.getElementById('faq-' + data.source)) {
            const link = document.createElement('a');
            link.href = '#faq-' + data.source;
            link.textContent = form.dataset.source;
            link.onclick = () => { document.getElementById('faq-' + data.source).open = true; };
            output.append(' ', link);
        }
    } catch {
        output.textContent = form.dataset.error;
    } finally {
        button.disabled = false;
    }
});
