import { useState } from 'react';
import type { Bootstrap } from '../../api/types';
import { useScope, useSetSearch, useRefreshInterval } from '../../lib/state';
import { clock, dateTime } from '../../lib/format';
import { Combobox } from '../Combobox';
import { Icon } from '../Icon';
import { Popover } from '../Popover';

/**
 * Topbar = context: the global scope (service, environment, window, refresh),
 * the host's connection profiles, search and copy-link. Every control writes
 * the URL, so the whole view stays shareable.
 */
export function TopBar({ boot, onPalette }: { boot: Bootstrap; onPalette: () => void }) {
    const scope = useScope();
    const set = useSetSearch();
    const refresh = useRefreshInterval();
    const [copied, setCopied] = useState(false);

    const services = boot.scope.services;
    const envs = boot.scope.environments;
    const serviceForced = boot.scope.servicesLocked && services.length === 1;
    const envForced = boot.scope.environmentsLocked && envs.length === 1;

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(window.location.href);
            setCopied(true);
            setTimeout(() => setCopied(false), 1400);
        } catch {
            /* clipboard unavailable */
        }
    };

    return (
        <header className="t-topbar">
            <div className="t-topbar-scope">
                {!serviceForced && (
                    <Combobox
                        label="Service"
                        value={scope.service}
                        mono
                        width={170}
                        options={[...(boot.scope.servicesLocked ? [] : [{ value: '', label: 'All services' }]), ...services.map((s) => ({ value: s, label: s }))]}
                        onChange={(v) => set({ service: v })}
                    />
                )}
                {!envForced && envs.length > 0 && (
                    <Combobox
                        label="Env"
                        value={scope.env}
                        mono
                        width={130}
                        options={[...(boot.scope.environmentsLocked ? [] : [{ value: '', label: 'All' }]), ...envs.map((s) => ({ value: s, label: s }))]}
                        onChange={(v) => set({ env: v })}
                    />
                )}
                {boot.scope.error && <span className="t-pill t-pill-warn" title={boot.scope.error}>scope unavailable</span>}
            </div>
            <div className="t-topbar-spacer" />
            <PeriodPicker boot={boot} />
            <Combobox
                label=""
                value={String(refresh)}
                width={78}
                options={boot.refreshIntervals.map((s) => ({ value: String(s), label: s === 0 ? 'Off' : `${s}s` }))}
                onChange={(v) => set({ refresh: v === '0' ? undefined : v })}
                className="t-refresh"
            />
            {boot.connections.length > 0 && (
                <Combobox
                    label="Backend"
                    value={boot.currentConnection}
                    placeholder="Connection"
                    options={boot.connections.map((c) => ({ value: c.value, label: c.label }))}
                    onChange={(v) => {
                        const target = boot.connections.find((c) => c.value === v);
                        if (target) window.location.href = target.url;
                    }}
                />
            )}
            <button type="button" className="t-btn t-btn-ghost t-kbd-btn" onClick={onPalette} title="Search (⌘K)">
                <Icon name="search" size={14} />
                <kbd>⌘K</kbd>
            </button>
            {boot.app.copyLink && (
                <button type="button" className="t-iconbtn" onClick={copyLink} title="Copy link to this view">
                    <Icon name={copied ? 'sparkle' : 'link'} size={15} />
                </button>
            )}
        </header>
    );
}

function toLocal(sec: string): string {
    const n = Number(sec);
    if (!Number.isFinite(n) || n <= 0) return '';
    const d = new Date(n * 1000);
    const pad = (x: number) => String(x).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function PeriodPicker({ boot }: { boot: Bootstrap }) {
    const scope = useScope();
    const set = useSetSearch();
    const custom = Boolean(scope.from && scope.to);
    const [from, setFrom] = useState(toLocal(scope.from ?? ''));
    const [to, setTo] = useState(toLocal(scope.to ?? ''));

    return (
        <div className="t-period">
            <div className="t-seg" role="group" aria-label="Time window">
                {boot.periods.map((p) => (
                    <button
                        type="button"
                        key={p.value}
                        className={!custom && scope.period === p.value ? 'is-on' : ''}
                        onClick={() => set({ period: p.value, from: undefined, to: undefined })}
                    >
                        {p.label}
                    </button>
                ))}
            </div>
            <Popover
                trigger={(toggle) => (
                    <button type="button" className={`t-btn t-btn-ghost t-btn-sm ${custom ? 'is-on' : ''}`} onClick={toggle} title="Custom range">
                        <Icon name="clock" size={14} />
                        {custom ? <span className="mono">{rangeLabel(Number(scope.from) * 1000, Number(scope.to) * 1000)}</span> : null}
                    </button>
                )}
            >
                {(close) => (
                    <form
                        className="t-range-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            const f = Math.floor(new Date(from).getTime() / 1000);
                            const t = Math.floor(new Date(to).getTime() / 1000);
                            if (Number.isFinite(f) && Number.isFinite(t) && f < t) {
                                set({ from: String(f), to: String(t) });
                                close();
                            }
                        }}
                    >
                        <label>From<input type="datetime-local" className="t-input" value={from} onChange={(e) => setFrom(e.target.value)} /></label>
                        <label>To<input type="datetime-local" className="t-input" value={to} onChange={(e) => setTo(e.target.value)} /></label>
                        <div className="t-row-gap">
                            <button type="submit" className="t-btn t-btn-primary t-btn-sm">Apply</button>
                            {custom && <button type="button" className="t-btn t-btn-ghost t-btn-sm" onClick={() => { set({ from: undefined, to: undefined }); close(); }}>Clear</button>}
                        </div>
                    </form>
                )}
            </Popover>
        </div>
    );
}

/** Compact custom-range label: times only when both ends fall on the same day. */
function rangeLabel(from: number, to: number): string {
    const sameDay = new Date(from).toDateString() === new Date(to).toDateString();
    return sameDay ? `${clock(from, false)} → ${clock(to, false)}` : `${dateTime(from).slice(0, -3)} → ${dateTime(to).slice(0, -3)}`;
}
