import React, { useId, useState } from 'react';

const color = (i, count) => `color-mix(in srgb, var(--cu-brand) ${Math.round(100 - (i / Math.max(1, count - 1)) * 68)}%, var(--cu-chart-surface))`;
const valueOf = (v) => Number.isFinite(Number(v)) ? Number(v) : 0;

export default function TemplateChart({ heading, type, data, locale, empty, total }) {
    const [active, select] = useState(null);
    const id = useId().replace(/[^a-zA-Z0-9]/g, '');
    const labels = data.labels || [];
    const datasets = data.datasets || [];
    const format = (n) => new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(n);
    const formatCompact = (n) => new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 }).format(n);
    if (!labels.length || !datasets.length) return <p className="cu-chart-empty">{empty}</p>;
    const donut = type === 'doughnut';
    const values = labels.map((_, i) => valueOf(datasets[0].data[i]));
    const sum = values.reduce((a, b) => a + Math.max(0, b), 0);
    const lo = Math.min(0, ...datasets.flatMap(s => s.data.map(valueOf)));
    const hi = Math.max(0, ...datasets.flatMap(s => s.data.map(valueOf)));
    const span = hi - lo || 1;
    const y = (v) => 180 - ((v - lo) / span) * 170;
    const selected = active === null ? null : labels[active];
    const detail = selected === null ? heading : `${selected}: ${datasets.map(s => `${s.label} ${format(valueOf(s.data[active]))}`).join(' · ')}`;
    let offset = 0;
    return <div className={`cu-v42-chart ${donut ? 'cu-v42-donut' : ''}`}>
        {donut ? <div className="cu-v42-ring" role="img" aria-label={detail}>
            <svg viewBox="0 0 200 200" aria-hidden="true">
                <circle cx="100" cy="100" r="76" fill="none" stroke="var(--cu-chart-grid)" strokeWidth="17" />
                {values.map((n, i) => {
                    const fraction = sum ? Math.max(0, n) / sum : 0;
                    const start = offset; offset += fraction;
                    return <circle key={i} cx="100" cy="100" r="76" fill="none" stroke={color(i, labels.length)}
                        strokeWidth={active === i ? 22 : 17} pathLength="1"
                        strokeDasharray={`${fraction} ${Math.max(0, 1 - fraction)}`}
                        strokeDashoffset={-start} transform="rotate(-90 100 100)" />;
                })}
            </svg>
            <div className="cu-v42-ring-total"><strong>{format(sum)}</strong><small>{total}</small></div>
        </div> : <div className="cu-v42-plot">
            <div className="cu-v42-axis" aria-hidden="true">{[hi, lo + span * .5, lo].map((n, i) => <span key={i}>{formatCompact(n)}</span>)}</div>
            <div className="cu-v42-bar-area">
                <svg viewBox="0 0 600 200" preserveAspectRatio="none" role="img" aria-label={detail}>
                    <defs>{datasets.map((_, s) => <linearGradient id={`${id}-${s}`} key={s} x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor={s ? 'var(--cu-sky)' : 'var(--cu-brand)'} /><stop offset="100%" stopColor="var(--cu-sky)" stopOpacity=".35" /></linearGradient>)}</defs>
                    {[hi, lo + span * .5, lo].map((n, i) => <line key={i} x1="0" x2="600" y1={y(n)} y2={y(n)} stroke="var(--cu-chart-grid)" />)}
                    {labels.flatMap((label, i) => datasets.map((s, j) => {
                        const n = valueOf(s.data[i]);
                        const group = 600 / labels.length;
                        const w = Math.min(36, group * .72 / datasets.length);
                        const x = i * group + group / 2 - datasets.length * w / 2 + j * w;
                        return <rect key={`${i}-${j}`} x={x} y={Math.min(y(n), y(0))} width={Math.max(1, w - 2)} height={Math.abs(y(n) - y(0))} rx="4"
                            fill={`url(#${id}-${j})`} opacity={active === null || active === i ? 1 : .38} />;
                    }))}
                </svg>
                <div className="cu-v42-bar-targets">{labels.map((label, i) => <button type="button" key={i}
                    onPointerEnter={() => select(i)} onPointerLeave={() => select(null)} onFocus={() => select(i)} onBlur={() => select(null)} onClick={() => select(i)}
                    aria-label={`${label}: ${datasets.map(s => `${s.label} ${format(valueOf(s.data[i]))}`).join(', ')}`}><span>{label}</span></button>)}</div>
            </div>
        </div>}
        {donut ? <div className="cu-v42-ring-legend">{labels.map((label, i) => <button type="button" key={i} onPointerEnter={() => select(i)} onPointerLeave={() => select(null)} onFocus={() => select(i)} onBlur={() => select(null)} onClick={() => select(i)}>
            <i style={{ background: color(i, labels.length) }} /><span>{label}</span><strong>{format(values[i])}</strong><small>{sum ? format(Math.max(0, values[i]) / sum * 100) : '0'}%</small>
        </button>)}</div> : datasets.length > 1 ? <div className="cu-v42-series">{datasets.map((s, i) => <span key={i}><i style={{ background: i ? 'var(--cu-sky)' : 'var(--cu-brand)' }} />{s.label}</span>)}</div> : null}
        <div className={`cu-v42-selection ${active !== null ? 'is-active' : ''}`} aria-live="polite">{detail}</div>
    </div>;
}
