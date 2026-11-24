import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

// Progressive enhancement only: Keycloak owns every form, action and credential.
function GlassLight() {
    return <><span className="cu-glass-chip cu-glass-chip-start" /><span className="cu-glass-chip cu-glass-chip-end" /><span className="cu-glass-rim" /></>;
}

function OtpPanes({ input }) {
    const [value, setValue] = useState(input.value);
    const [focused, setFocused] = useState(document.activeElement === input);
    useEffect(() => {
        const update = () => setValue(input.value);
        const focus = () => setFocused(true);
        const blur = () => setFocused(false);
        input.addEventListener('input', update);
        input.addEventListener('change', update);
        input.addEventListener('focus', focus);
        input.addEventListener('blur', blur);
        // Native OTP/paste/autofill remain available; no validation or submission here.
        input.classList.add('cu-otp-enhanced');
        return () => {
            input.classList.remove('cu-otp-enhanced');
            input.removeEventListener('input', update);
            input.removeEventListener('change', update);
            input.removeEventListener('focus', focus);
            input.removeEventListener('blur', blur);
        };
    }, [input]);
    const length = Math.max(6, value.length);
    // Never truncate a code if a future server policy uses a different length.
    return <span className={`cu-otp-panes ${focused ? 'is-focused' : ''}`} style={{ '--cu-otp-count': length }}>
        {Array.from({ length }, (_, index) => <span key={index} className={`cu-otp-pane ${value[index] ? 'is-filled' : ''} ${focused && index === Math.min(value.length, length - 1) ? 'is-current' : ''}`}>{value[index] || ''}</span>)}
    </span>;
}

function enhance() {
    const card = document.querySelector('.card-pf');
    if (!card || card.dataset.cuGlass) return;
    card.dataset.cuGlass = 'ready';
    const light = document.createElement('div');
    light.className = 'cu-glass-decoration';
    light.setAttribute('aria-hidden', 'true');
    card.prepend(light);
    createRoot(light).render(<GlassLight />);

    const otp = document.querySelector('input[name="otp"]');
    if (otp) {
        otp.autocomplete = 'one-time-code';
        otp.inputMode = 'numeric';
        const wrapper = document.createElement('div');
        wrapper.className = 'cu-otp-field';
        otp.before(wrapper);
        wrapper.append(otp);
        const panes = document.createElement('div');
        panes.className = 'cu-otp-visual';
        panes.setAttribute('aria-hidden', 'true');
        wrapper.append(panes);
        createRoot(panes).render(<OtpPanes input={otp} />);
    }
    // Keep native autocomplete/password managers and server errors intact.
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', () => {
            if (form.checkValidity()) form.classList.add('cu-auth-submitting');
        });
    });
    window.addEventListener('pageshow', () => document.querySelectorAll('.cu-auth-submitting')
        .forEach(form => form.classList.remove('cu-auth-submitting')));
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhance, { once: true });
else enhance();
