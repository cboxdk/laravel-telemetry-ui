import { useVirtualizer } from '@tanstack/react-virtual';
import { useContext, useRef } from 'react';
import type { ErrorRow, Group, SpanRow } from '../../api/types';
import { ago, clock, count, dateTime, ms, percent, statusTone } from '../../lib/format';
import { usePrefetch } from '../../api/hooks';
import { useGo } from '../../lib/links';
import { useScrollParent } from '../../lib/scrollParent';
import { BootContext, DimensionValue, useDimension } from '../DimensionValue';
import { Sparkline } from '../charts/Sparkline';

const CHIP_KEYS = ['user.id', 'client.address'];

/** ↑/↓ (or j/k) move between result rows; Enter opens the focused one. */
function focusSibling(row: HTMLElement, dir: 1 | -1): void {
    const rows = [...(row.closest('.t-list')?.querySelectorAll<HTMLElement>('.t-lrow') ?? [])];
    const next = rows[rows.indexOf(row) + dir];
    next?.focus();
    next?.scrollIntoView({ block: 'nearest' });
}

/** Request/trace rows: time · status · target + dimension chips · duration. */
export function SpanList({ rows, selected, onSelect, fresh = 0 }: { rows: SpanRow[]; selected?: string; onSelect?: (row: SpanRow) => void; /** How many leading rows arrived by live tail — they flash once. */ fresh?: number }) {
    const list = useRef<HTMLDivElement>(null);
    const scroll = useScrollParent(list);
    const boot = useContext(BootContext);
    const go = useGo();
    const prefetch = usePrefetch();
    const custom = (boot?.dimensions ?? []).filter((d) => !d.builtin).map((d) => d.key);

    const virtualizer = useVirtualizer({ count: rows.length, getScrollElement: () => scroll.element, estimateSize: () => 52, overscan: 16, scrollMargin: scroll.margin });
    const items = virtualizer.getVirtualItems();
    const render = (row: SpanRow, index: number) => {
        const chips = [...custom, ...CHIP_KEYS].filter((k) => row.attributes[k]);
        return (
            <div
                className={`t-lrow ${selected === row.traceId ? 'is-sel' : ''} ${row.error ? 'is-error' : ''} ${index < fresh ? 'is-new' : ''}`}
                role="button"
                tabIndex={0}
                onMouseEnter={() => prefetch({ to: 'trace', id: row.traceId, at: row.startMs })}
                onClick={(e) => {
                    if ((e.target as HTMLElement).closest('button:not(.t-lrow),a')) return;
                    if (onSelect) onSelect(row);
                    else go({ to: 'trace', id: row.traceId, at: row.startMs }, { replaceDrawer: true });
                }}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') go({ to: 'trace', id: row.traceId, at: row.startMs }, { replaceDrawer: true });
                    else if (e.key === 'ArrowDown' || e.key === 'j') { e.preventDefault(); focusSibling(e.currentTarget, 1); }
                    else if (e.key === 'ArrowUp' || e.key === 'k') { e.preventDefault(); focusSibling(e.currentTarget, -1); }
                }}
            >
                <span className="t-lrow-time mono" title={dateTime(row.startMs)}>{clock(row.startMs)}</span>
                {row.status ? <span className={`t-status t-status-${statusTone(row.status)}`}>{row.status}</span> : <span className={`t-status t-status-${row.error ? 'danger' : 'dim'}`}>{row.error ? 'ERR' : row.browser ? 'WEB' : '—'}</span>}
                <span className="t-lrow-main">
                    <span className="t-lrow-path mono">
                        {row.method && <span className="t-method">{row.method}</span>}
                        {row.target ?? row.name}
                    </span>
                    {chips.length > 0 && (
                        <span className="t-lrow-tags">
                            {chips.map((k) => <DimensionValue key={k} dimKey={k} value={row.attributes[k]!} chip label />)}
                        </span>
                    )}
                </span>
                <span className={`t-lrow-dur mono ${row.durationMs > 1000 ? 't-tone-warn' : ''}`}>{ms(row.durationMs)}</span>
            </div>
        );
    };

    return (
        <div className="t-list" ref={list}>
            {items.length === 0 && rows.length > 0
                ? rows.slice(0, 60).map((r, i) => <div key={`${r.traceId}-${i}`}>{render(r, i)}</div>)
                : (
                    <div style={{ height: virtualizer.getTotalSize(), position: 'relative' }}>
                        {items.map((v) => (
                            <div key={v.key} style={{ position: 'absolute', top: 0, left: 0, right: 0, height: v.size, transform: `translateY(${v.start - scroll.margin}px)` }}>
                                {render(rows[v.index]!, v.index)}
                            </div>
                        ))}
                    </div>
                )}
        </div>
    );
}

/** Sentry-style issue rows for the errors signal. */
export function ErrorList({ rows }: { rows: ErrorRow[] }) {
    const go = useGo();
    const prefetch = usePrefetch();
    return (
        <div className="t-list t-errlist">
            {rows.map((row) => (
                <div key={row.group} className="t-erow" role="button" tabIndex={0} onMouseEnter={() => prefetch({ to: 'error', group: row.group })} onClick={() => go({ to: 'error', group: row.group }, { replaceDrawer: true })} onKeyDown={(e) => e.key === 'Enter' && go({ to: 'error', group: row.group }, { replaceDrawer: true })}>
                    <span className="t-erow-main">
                        <span className="t-erow-type mono">{row.type || 'Error'}</span>
                        <span className="t-erow-msg">{row.message}</span>
                        <span className="t-erow-meta">
                            <span className={`t-badge ${row.source === 'backend' ? 't-badge-info' : row.source === 'frontend' ? 't-badge-warn' : 't-badge-danger'}`}>{row.source}</span>
                            {row.services.map((s) => <span key={s} className="mono t-dim">{s}</span>)}
                            <span className="t-dim">first {ago(row.firstMs)} · last {ago(row.lastMs)}</span>
                        </span>
                    </span>
                    <Sparkline points={row.spark} tone="danger" bars width={96} height={26} />
                    <span className="t-erow-num"><strong className="mono">{count(row.count)}</strong><span className="t-dim">events</span></span>
                    <span className="t-erow-num"><strong className="mono">{count(row.users)}</strong><span className="t-dim">users</span></span>
                </div>
            ))}
        </div>
    );
}

/** Group-by breakdown: each value is a drill-down (filter / open entity). */
export function GroupTable({ groupKey, groups, exact, onFilter }: { groupKey: string; groups: Group[]; exact?: boolean; onFilter?: (value: string, errorsOnly: boolean) => void }) {
    const max = Math.max(1, ...groups.map((g) => g.count));
    const label = useDimension(groupKey)?.label ?? groupKey;
    return (
        <div className="t-groups">
            <div className="t-groups-head">
                <span title={groupKey}>{label}</span>
                <span className="is-num">Count</span>
                <span className="is-num">Errors</span>
                <span className="is-num">Avg</span>
                <span className="is-num">P95</span>
            </div>
            {groups.map((g) => (
                <div key={g.value} className="t-groups-row">
                    <span className="t-groups-val">
                        <i style={{ width: `${(g.count / max) * 100}%` }} />
                        {g.value === '(none)' ? <span className="t-dim mono">(none)</span> : <DimensionValue dimKey={groupKey} value={g.value}><span className="mono">{g.value}</span></DimensionValue>}
                    </span>
                    {onFilter ? (
                        <button type="button" className="is-num mono t-groups-hit" onClick={() => onFilter(g.value, false)} title="Show these">{count(g.count)}</button>
                    ) : <span className="is-num mono">{count(g.count)}</span>}
                    {onFilter && g.errorRate > 0 ? (
                        <button type="button" className={`is-num mono t-groups-hit ${g.errorRate > 0.01 ? 't-tone-danger' : 't-dim'}`} onClick={() => onFilter(g.value, true)} title="Show the failed ones">{percent(g.errorRate)}</button>
                    ) : <span className={`is-num mono ${g.errorRate > 0.01 ? 't-tone-danger' : 't-dim'}`}>{exact && g.errors === 0 ? '—' : percent(g.errorRate)}</span>}
                    <span className="is-num mono">{ms(g.avg)}</span>
                    <span className="is-num mono">{ms(g.p95)}</span>
                </div>
            ))}
        </div>
    );
}
