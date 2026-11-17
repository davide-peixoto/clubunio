const root = document.querySelector('[data-security]');
const $ = selector => root.querySelector(selector);
const mode = root.dataset.mode;
const result = $('[data-result]');
const login = $('[data-password]');
const enrollment = $('[data-enrollment]');
const totp = $('[data-totp]');
let busy = false;

const bytes = text => Uint8Array.from(atob(text.replaceAll('-', '+').replaceAll('_', '/')), c => c.charCodeAt(0));
const encode = buffer => btoa(String.fromCharCode(...new Uint8Array(buffer))).replaceAll('+', '-').replaceAll('/', '_').replace(/=+$/, '');

async function request(operation, payload = {}) {
    const response = await fetch(root.dataset.url + '/' + operation, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': root.dataset.token },
        body: JSON.stringify({ ...payload, mode, ...(mode === 'mobile' ? { pair: root.dataset.pair, confirmation: $('[name="confirmation"]').value } : {}) }),
    });
    if (!response.ok) throw new Error(response.status === 429 ? root.dataset.retry : root.dataset.error);
    return response.json();
}

async function run(action) {
    if (busy) return;
    busy = true;
    const buttons = [...root.querySelectorAll('button:not(:disabled)')];
    buttons.forEach(button => button.disabled = true);
    result.hidden = true;
    try { await action(); }
    catch (error) {
        result.textContent = error.message === root.dataset.retry ? root.dataset.retry : root.dataset.error;
        result.hidden = false;
    } finally {
        buttons.forEach(button => button.disabled = false);
        busy = false;
    }
}

function finish(data) {
    if (data.setup_code) {
        $('[data-setup]').open = true;
        enrollment.elements.username.value = login.elements.username.value;
        enrollment.elements.invitation.value = data.setup_code;
        enrollment.elements.password.focus();
    } else if (data.redirect) {
        const target = new URL(data.redirect, location.origin);
        if (target.origin !== location.origin) throw new Error();
        location.assign(target.href);
    } else if (data.paired) {
        login.reset();
        $('[name="confirmation"]').value = '';
        login.hidden = $('[data-setup]').hidden = $('[data-passkey]').hidden = true;
        result.textContent = root.dataset.paired;
        result.hidden = false;
    }
}

function recovery(codes) {
    enrollment.reset();
    $('[data-secret]').textContent = '';
    totp.hidden = true;
    $('[data-setup]').hidden = true;
    login.hidden = $('[data-passkey]').hidden = true;
    $('[data-codes]').replaceChildren(...codes.map(code => Object.assign(document.createElement('li'), { textContent: code })));
    $('[data-recovery]').hidden = false;
}

function passkeyAvailable() {
    return window.isSecureContext && typeof PublicKeyCredential !== 'undefined';
}
$('[data-passkey]').disabled = !passkeyAvailable();
enrollment.querySelector('[value="passkey"]').disabled = !passkeyAvailable();
$('[data-passkey]').onclick = () => run(async () => {
    const publicKey = await request('passkey-options');
    publicKey.challenge = bytes(publicKey.challenge);
    const { rawId, response } = await navigator.credentials.get({ publicKey });
    finish(await request('passkey', { id: encode(rawId), client: encode(response.clientDataJSON),
        authenticator: encode(response.authenticatorData), signature: encode(response.signature) }));
});
login.onsubmit = event => {
    event.preventDefault();
    run(async () => {
        try { finish(await request('password', Object.fromEntries(new FormData(login)))); }
        finally { login.elements.password.value = ''; login.elements.code.value = ''; }
    });
};
enrollment.onsubmit = event => {
    event.preventDefault();
    const method = event.submitter?.value;
    run(async () => {
        const payload = Object.fromEntries(new FormData(enrollment));
        if (!payload.invitation && !enrollment.elements.invitation.required) {
            const { setup_code } = await request('setup-start', { username: payload.username, password: payload.password });
            enrollment.elements.invitation.value = payload.invitation = setup_code;
        }
        if (method === 'passkey') {
            const publicKey = await request('setup-passkey', payload);
            publicKey.challenge = bytes(publicKey.challenge);
            publicKey.user.id = bytes(publicKey.user.id);
            const { response } = await navigator.credentials.create({ publicKey });
            recovery((await request('setup-passkey-confirm', { ...payload, client: encode(response.clientDataJSON),
                attestation: encode(response.attestationObject) })).codes);
        } else if (method === 'totp') {
            const data = await request('setup-totp', payload);
            $('[data-secret]').textContent = data.secret;
            totp.hidden = false;
            totp.querySelector('input').focus();
        } else if (method === 'confirm') {
            recovery((await request('setup-totp-confirm', payload)).codes);
        }
    });
};
$('[data-finish]').onclick = () => {
    $('[data-codes]').replaceChildren();
    $('[data-recovery]').hidden = true;
    login.hidden = $('[data-passkey]').hidden = false;
    $('[data-password]').elements.username.focus();
};
