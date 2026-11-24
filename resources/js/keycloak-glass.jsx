import React, { useEffect, useLayoutEffect, useRef, useState, useId } from 'react';
import { createRoot } from 'react-dom/client';
import { messages } from './keycloak-glass-messages.js';

const reducedMotion = () => document.documentElement.dataset.cuMotion === 'reduced';
function motionPreference() {
    try { const preference = localStorage.getItem('clubunio-auth-motion'); if (['full', 'reduced'].includes(preference)) return preference; } catch { /* Storage may be unavailable. */ }
    return matchMedia('(prefers-reduced-motion: reduce)').matches ? 'reduced' : 'full';
}
function MotionToggle() {
    const [motion, setMotion] = useState(document.documentElement.dataset.cuMotion);
    const language = document.documentElement.lang.split('-')[0];
    const text = messages[language] || messages.en;
    const toggle = () => {
        const next = motion === 'full' ? 'reduced' : 'full';
        document.documentElement.dataset.cuMotion = next;
        try { localStorage.setItem('clubunio-auth-motion', next); } catch { /* In-memory choice still works. */ }
        setMotion(next); window.dispatchEvent(new Event('clubunio:motion-change'));
    };
    return <button type="button" className="cu-motion-toggle" onClick={toggle} aria-pressed={motion === 'full'}>{motion === 'full' ? text.motionDisable : text.motionEnable}</button>;
}
// Geometry/docking follow the supplied Glassy Login v3 and OTP v20 templates.
const cardPath = (w, h) => `M 88 0 H ${w - 24} Q ${w} 0 ${w} 24 V ${h - 88} Q ${w} ${h - 80} ${w - 6} ${h - 74} L ${w - 74} ${h - 6} Q ${w - 80} ${h} ${w - 88} ${h} H 24 Q 0 ${h} 0 ${h - 24} V 88 Q 0 80 6 74 L 74 6 Q 80 0 88 0 Z`;
const chipPath = 'M 22 0 L 72 0 Q 88 0 84 11.2 L 11.2 84 Q 0 88 0 72 L 0 22 A 22 22 0 0 1 22 0 Z';

function GlassBackground() {
    return <svg viewBox="0 0 1600 1000" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
        <defs><linearGradient id="cu-metal"><stop stopColor="#030810"/><stop offset=".4" stopColor="#8298aa"/><stop offset=".48" stopColor="#050e1b"/><stop offset=".6" stopColor="var(--cu-auth-accent)"/><stop offset=".68" stopColor="#061320"/><stop offset="1" stopColor="#02060c"/></linearGradient></defs>
        <g fill="none" stroke="url(#cu-metal)">
            <path strokeWidth="104" d="M-320-220 C200 30 230 420 1010 290 S1510 590 1920 410"/>
            <path strokeWidth="52" d="M-300-260 C480-220 650 180 1300 120 S1760 250 1880 120"/>
            <path strokeWidth="84" d="M-300 240 C330 470 440 1090 1320 1080 S1770 810 1930 1210"/>
            <path strokeWidth="28" d="M-280 260 C300 490 490 1020 1350 1000"/>
        </g>
        <g fill="none" stroke="var(--cu-auth-accent)" strokeOpacity=".35" strokeWidth="2">
            <path d="M-300-190 C210 50 240 440 1010 310 S1510 610 1920 430"/>
            <path d="M-320 210 C320 440 450 1060 1320 1050"/>
        </g>
    </svg>;
}

function GlassLight({ card }) {
    const [size, setSize] = useState({ w: card.offsetWidth, h: card.offsetHeight });
    const start = useRef(null), end = useRef(null);
    const id = useId().replaceAll(':', '');
    const [motionEpoch, setMotionEpoch] = useState(0);
    useEffect(() => {
        const update = () => setMotionEpoch(epoch => epoch + 1);
        window.addEventListener('clubunio:motion-change', update);
        return () => window.removeEventListener('clubunio:motion-change', update);
    }, []);
    useLayoutEffect(() => {
        const observer = new ResizeObserver(() => setSize({ w: card.offsetWidth, h: card.offsetHeight }));
        observer.observe(card); return () => observer.disconnect();
    }, [card]);
    useEffect(() => {
        if (reducedMotion()) return;
        const dx = (card.offsetWidth - 88) / 2, dy = (card.offsetHeight - 88) / 2;
        const animations = [start.current, end.current].map((chip, index) => {
            const sign = index === 0 ? 1 : -1;
            return chip.animate([
                { transform: `translate(${sign * dx}px, ${sign * dy}px) scale(.96)` },
                { transform: `translate(${sign * dx}px, ${sign * dy}px) scale(1.04)`, offset: .24 },
                { transform: 'translate(0, 0) scale(1.025)', offset: .85 },
                { transform: 'translate(0, 0) scale(1)' },
            ], { duration: 1350, easing: 'cubic-bezier(.22,1,.36,1)' });
        });
        return () => animations.forEach(animation => animation.cancel());
    }, [card, motionEpoch]);
    const path = cardPath(size.w, size.h);
    return <>
        <div className="cu-glass-surface" style={{ clipPath: `path('${path}')` }}/>
        <svg className="cu-glass-outline" viewBox={`0 0 ${size.w} ${size.h}`}>
            <defs><linearGradient id={id}><stop stopColor="#e0f2fe" stopOpacity=".8"/><stop offset=".48" stopColor="var(--cu-auth-accent)" stopOpacity=".18"/><stop offset="1" stopColor="#e0f2fe" stopOpacity=".65"/></linearGradient></defs>
            <path d={path} fill="none" stroke={`url(#${id})`} strokeWidth="1.5"/>
            <path className="cu-glass-comet" d={path} fill="none" stroke="var(--cu-auth-accent)" strokeWidth="2" pathLength="1000" strokeDasharray="100 900"/>
        </svg>
        {[start, end].map((ref, index) => <div key={index} ref={ref} className={`cu-glass-chip cu-glass-chip-${index ? 'end' : 'start'}`}>
            <div className="cu-chip-float"><div className="cu-chip-glass" style={{ clipPath: `path('${chipPath}')` }}/>
                <svg viewBox="0 0 88 88"><path d={chipPath} fill="none" stroke={`url(#${id})`} strokeWidth="1.5"/></svg>
            </div>
        </div>)}
        <div className="cu-glass-bloom"/><div className="cu-glass-sheen" style={{ clipPath: `path('${path}')` }}/>
    </>;
}

const otpDigits = input => {
    const length = Number(input.dataset.otpLength || 6);
    return Number.isInteger(length) && length >= 4 && length <= 10 ? length : 6;
};

function OtpPanes({ input, wrapper, autoSubmit }) {
    // No cloned digits: text, selection, paste and autofill belong to the native input.
    const [state, setState] = useState({ length: input.value.length, selection: input.selectionStart || 0, focus: document.activeElement === input });
    useLayoutEffect(() => {
        input.classList.add('cu-otp-native');
        const context = document.createElement('canvas').getContext('2d');
        const sync = () => {
            setState(previous => {
                const next = { length: input.value.length, selection: input.selectionStart || 0, focus: document.activeElement === input };
                return Object.keys(next).every(key => previous[key] === next[key]) ? previous : next;
            });
            const count = Math.max(otpDigits(input), Math.min(10, input.value.length));
            const width = wrapper.clientWidth, font = getComputedStyle(input);
            context.font = `${font.fontWeight} ${font.fontSize} ${font.fontFamily}`;
            const glyph = context.measureText('0').width;
            const gap = width < 300 ? 6 : 8;
            // Reserve real, visible space for password-manager/OTP badges.
            const step = (width - 36 + gap) / count;
            wrapper.style.setProperty('--cu-otp-count', count);
            wrapper.style.setProperty('--cu-otp-gap', `${gap}px`);
            wrapper.style.setProperty('--cu-otp-spacing', `${step - glyph}px`);
            wrapper.style.setProperty('--cu-otp-padding', `${Math.max(0, (step - gap - glyph) / 2)}px`);
            wrapper.classList.toggle('cu-otp-long', input.value.length > 10);
            input.form?.style.setProperty('--cu-otp-progress', `${Math.min(100, input.value.length / otpDigits(input) * 100)}%`);
            autoSubmit();
        };
        const events = ['input', 'change', 'focus', 'blur', 'select', 'keyup', 'click'];
        events.forEach(event => input.addEventListener(event, sync));
        // Also detect extension .value writes that do not emit input/change.
        const poll = setInterval(() => { if (!document.hidden) sync(); }, 180);
        const observer = new ResizeObserver(sync); observer.observe(wrapper); sync();
        return () => {
            clearInterval(poll); observer.disconnect();
            events.forEach(event => input.removeEventListener(event, sync));
            input.classList.remove('cu-otp-native');
        };
    }, [input, wrapper, autoSubmit]);
    const length = Math.max(otpDigits(input), Math.min(10, state.length));
    return <div className="cu-otp-panes">{Array.from({ length }, (_, index) => <span key={index}
        style={{ '--cu-otp-index': index, '--cu-merge-x': `${(length - 1) / 2 - index}` }}
        className={`cu-otp-pane ${index < state.length ? 'is-filled' : ''} ${state.focus && index === Math.min(state.selection, length - 1) ? 'is-current' : ''}`}><span className="cu-otp-sweep"/></span>)}</div>;
}

function enhanceOtp(input) {
    input.autocomplete = 'one-time-code'; input.inputMode = 'numeric'; input.spellcheck = false;
    const wrapper = document.createElement('div'); wrapper.className = 'cu-otp-field';
    input.before(wrapper); wrapper.append(input);
    const panes = document.createElement('div'); panes.className = 'cu-otp-visual'; panes.setAttribute('aria-hidden', 'true');
    wrapper.append(panes);
    const form = input.form;
    if (!form) return;
    const wasReadOnly = input.readOnly;
    let timer, autoTimer, pending = false, resuming = false, submitted = false, composing = false;
    let observed = input.value;
    // A rejected code must not cause an extension-driven retry loop. Typing,
    // pasting or clicking the input rearms auto-confirm on an error page.
    let armed = input.getAttribute('aria-invalid') !== 'true';
    const validCode = value => new RegExp(`^[0-9]{${otpDigits(input)}}$`).test(value);
    const autoSubmit = () => {
        if (input.value === observed) return;
        observed = input.value;
        clearTimeout(autoTimer);
        if (!armed || composing || pending || submitted || input.readOnly || !validCode(observed)) return;
        const candidate = observed;
        autoTimer = setTimeout(() => {
            if (composing || pending || submitted || input.readOnly || input.value !== candidate || !validCode(candidate) || !form.checkValidity()) return;
            const button = form.querySelector('[type="submit"]:not(:disabled)');
            if (button) form.requestSubmit(button); else form.requestSubmit();
        }, 120);
    };
    input.addEventListener('input', event => { if (event.isTrusted) { armed = true; observed = ''; } autoSubmit(); });
    input.addEventListener('change', autoSubmit);
    ['pointerdown', 'keydown', 'paste'].forEach(event => input.addEventListener(event, e => { if (e.isTrusted) armed = true; }));
    input.addEventListener('compositionstart', () => { composing = true; clearTimeout(autoTimer); });
    input.addEventListener('compositionend', () => { composing = false; observed = ''; autoSubmit(); });
    input.addEventListener('paste', event => {
        if (event.isTrusted) { armed = true; observed = ''; }
        const text = event.clipboardData?.getData('text');
        // Plain codes use native paste. Normalize only numeric code separators.
        if (text && /^[\d\s\-\u200b]+$/.test(text) && /[\s\-\u200b]/.test(text)) {
            event.preventDefault();
            input.setRangeText(text.replace(/[\s\-\u200b]/g, ''), input.selectionStart, input.selectionEnd, 'end');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
    const restore = () => {
        clearTimeout(timer); clearTimeout(autoTimer); pending = false; resuming = false; submitted = false;
        observed = input.value; input.readOnly = wasReadOnly;
        form.classList.remove('cu-otp-scanning', 'cu-otp-merging', 'cu-auth-submitting');
    };
    window.addEventListener('pageshow', event => { if (event.persisted) restore(); });
    window.addEventListener('pagehide', restore);
    form.addEventListener('submit', event => {
        if (resuming) { submitted = true; return; }
        if (pending || submitted) { event.preventDefault(); event.stopImmediatePropagation(); return; }
        if (!form.checkValidity()) return;
        clearTimeout(autoTimer);
        if (reducedMotion() || input.value.length < otpDigits(input)) { submitted = true; return; }
        event.preventDefault(); event.stopImmediatePropagation();
        if (pending) return;
        pending = true; input.readOnly = true;
        const submitter = event.submitter;
        form.classList.add('cu-otp-scanning', 'cu-auth-submitting');
        timer = setTimeout(() => {
            form.classList.add('cu-otp-merging');
            timer = setTimeout(() => {
                // Pending animation only. Keycloak alone determines success/failure.
                input.readOnly = wasReadOnly; resuming = true;
                form.classList.remove('cu-otp-scanning', 'cu-otp-merging');
                if (submitter && !submitter.disabled) form.requestSubmit(submitter); else form.requestSubmit();
                resuming = false; timer = setTimeout(restore, 1500);
            }, 340);
        }, 540);
    }, true);
    createRoot(panes).render(<OtpPanes input={input} wrapper={wrapper} autoSubmit={autoSubmit}/>);
}

function enhance() {
    const card = document.querySelector('.card-pf');
    if (!card || card.dataset.cuGlass) return;
    card.dataset.cuGlass = 'motion-v5';
    document.documentElement.dataset.cuMotion = motionPreference();
    const stage = document.createElement('div'); stage.className = 'cu-auth-stage'; card.before(stage); stage.append(card);
    const background = document.createElement('div'); background.className = 'cu-auth-background'; background.setAttribute('aria-hidden', 'true');
    document.body.prepend(background); createRoot(background).render(<GlassBackground/>);
    const light = document.createElement('div'); light.className = 'cu-glass-decoration'; light.setAttribute('aria-hidden', 'true');
    card.prepend(light); createRoot(light).render(<GlassLight card={card}/>);
    const motionControl = document.createElement('div'); motionControl.className = 'cu-motion-control';
    stage.after(motionControl); createRoot(motionControl).render(<MotionToggle/>);
    const otp = document.querySelector('input[name="otp"]'); if (otp) enhanceOtp(otp);
    let frame;
    stage.addEventListener('pointermove', event => {
        if (reducedMotion() || event.pointerType !== 'mouse' || card.contains(document.activeElement)) return;
        const rect = stage.getBoundingClientRect(); cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => {
            card.style.setProperty('--cu-tilt-x', `${(0.5 - (event.clientY - rect.top) / rect.height) * 7}deg`);
            card.style.setProperty('--cu-tilt-y', `${((event.clientX - rect.left) / rect.width - .5) * 8}deg`);
            card.style.setProperty('--cu-glare-x', `${(event.clientX - rect.left) / rect.width * 100}%`);
            card.style.setProperty('--cu-glare-y', `${(event.clientY - rect.top) / rect.height * 100}%`);
        });
    });
    const resetTilt = () => { cancelAnimationFrame(frame); card.style.setProperty('--cu-tilt-x', '0deg'); card.style.setProperty('--cu-tilt-y', '0deg'); };
    stage.addEventListener('pointerleave', resetTilt); card.addEventListener('focusin', resetTilt);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhance, { once: true });
else enhance();
