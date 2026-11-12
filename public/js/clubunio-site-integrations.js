const integrations = document.querySelector('#site-integrations');
const analyticsId = integrations?.dataset.analytics;
if (analyticsId) {
    const panel = document.querySelector('#analytics-settings');
    const preference = 'clubunio-analytics-v1';
    const isTagManager = analyticsId.startsWith('GTM-');
    const disableKey = 'ga-disable-' + analyticsId;
    let started = false;
    function startAnalytics() {
        window[disableKey] = false;
        if (started) return;
        started = true;
        window.dataLayer = window.dataLayer || [];
        function gtag() { window.dataLayer.push(arguments); }
        gtag('consent', 'default', {
            analytics_storage: 'granted', ad_storage: 'denied',
            ad_user_data: 'denied', ad_personalization: 'denied'
        });
        if (isTagManager) {
            window.dataLayer.push({'gtm.start': Date.now(), event: 'gtm.js'});
        } else {
            gtag('js', new Date());
            gtag('config', analyticsId, {
                allow_google_signals: false,
                allow_ad_personalization_signals: false,
                page_location: location.origin + location.pathname
            });
        }
        const script = document.createElement('script');
        script.async = true;
        const scriptPath = isTagManager ? 'gtm.js' : 'gtag/js';
        script.src = 'https://www.googletagmanager.com/' + scriptPath + '?id=' + encodeURIComponent(analyticsId);
        document.head.append(script);
    }
    if (localStorage.getItem(preference) === 'allow') startAnalytics();
    if (!localStorage.getItem(preference)) panel.open = true;
    panel.querySelectorAll('button[data-choice]').forEach(button => {
        button.addEventListener('click', () => {
            const choice = button.dataset.choice;
            localStorage.setItem(preference, choice);
            if (choice === 'allow') {
                startAnalytics();
            } else {
                window[disableKey] = true;
                for (const cookie of document.cookie.split(';')) {
                    const name = cookie.split('=')[0].trim();
                    if (/^_ga(?:_|$)/.test(name)) {
                        for (const domain of ['', '; Domain=clubunio.com']) {
                            document.cookie = name + '=; Max-Age=0; Path=/' + domain;
                        }
                    }
                }
            }
            panel.open = false;
            if (choice === 'deny' && isTagManager && started) location.reload();
        });
    });
}

const captchaForm = document.querySelector('.inquiry-form');
const captchaKey = integrations?.dataset.captcha;
if (captchaForm && captchaKey) {
    let scriptPromise;
    let pending = false;
    captchaForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (pending) return;
        pending = true;
        const button = captchaForm.querySelector('button[type="submit"]');
        const result = document.querySelector('#inquiry-result');
        button.disabled = true;
        result.hidden = true;
        try {
            scriptPromise ??= new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(captchaKey);
                script.onload = resolve;
                script.onerror = reject;
                document.head.append(script);
            });
            const token = await Promise.race([
                scriptPromise.then(() => new Promise((resolve, reject) => {
                    grecaptcha.ready(() => grecaptcha.execute(captchaKey, {action: 'inquiry'}).then(resolve, reject));
                })),
                new Promise((_, reject) => setTimeout(reject, 12000))
            ]);
            captchaForm.elements.namedItem('captcha_token').value = token;
            HTMLFormElement.prototype.submit.call(captchaForm);
        } catch {
            scriptPromise = undefined;
            result.textContent = result.dataset.captcha;
            result.hidden = false;
            button.disabled = false;
            pending = false;
        }
    });
}
