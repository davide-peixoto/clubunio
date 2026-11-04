(() => {
    'use strict';
    const fragment = window.location.hash.slice(1);
    const confirmation = fragment.startsWith('confirm=');
    const token = confirmation ? fragment.slice(8) : fragment;
    history.replaceState(null, '', window.location.pathname);
    const message = document.getElementById('sr-message');
    const text = message.dataset;
    const pledge = document.getElementById('sr-pledge');
    const confirm = document.getElementById('sr-confirm');
    const key = crypto.randomUUID();
    const post = async (action, data) => {
        let response, body;
        try {
            response = await fetch(`/sponsor-run/${action}`, {method: 'POST', credentials: 'same-origin', cache: 'no-store', referrerPolicy: 'no-referrer', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify({...data, token})});
            body = await response.json();
        } catch {
            throw new Error(text.requestFailed);
        }
        if (!body || typeof body !== 'object') throw new Error(text.requestFailed);
        if (!response.ok) {
            const errors = body.errors && typeof body.errors === 'object'
                ? Object.values(body.errors).flat().filter(value => typeof value === 'string').join(' ') : '';
            throw new Error(errors || (typeof body.message === 'string' && body.message) || text.requestFailed);
        }
        return body;
    };
    if (!/^[a-f0-9]{64}$/.test(token)) { message.textContent = text.invalidLink; return; }
    if (confirmation) {
        message.textContent = text.confirmPledge;
        confirm.hidden = false;
    } else {
        post('info', {}).then(data => {
            document.getElementById('sr-title').textContent = data.title;
            document.getElementById('sr-alias').textContent = data.alias;
            document.getElementById('sr-date').textContent = text.eventDeadline.replace(/:date|:deadline/g,
                placeholder => placeholder === ':date' ? data.event_date : data.registration_deadline);
            document.getElementById('sr-event').hidden = false;
            message.textContent = '';
            pledge.hidden = false;
        }).catch(error => { message.textContent = error.message; });
    }
    pledge.addEventListener('submit', async event => {
        event.preventDefault();
        const button = pledge.querySelector('button');
        button.disabled = true;
        try {
            const data = Object.fromEntries(new FormData(pledge));
            data.consent = pledge.elements.consent.checked;
            data.request_key = key;
            data.payment_mode = 'invoice';
            const result = await post('submit', data);
            message.textContent = result.message;
            pledge.hidden = true;
        } catch (error) { message.textContent = error.message; button.disabled = false; }
    });
    confirm.addEventListener('submit', async event => {
        event.preventDefault();
        const button = confirm.querySelector('button');
        button.disabled = true;
        try { const result = await post('confirm', {}); message.textContent = result.message; confirm.hidden = true; }
        catch (error) { message.textContent = error.message; button.disabled = false; }
    });
})();
