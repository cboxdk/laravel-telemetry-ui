import { useVirtualizer } from '@tanstack/react-virtual';
import { useContext, useRef } from 'react';
import type { ErrorRow, Group, SpanRow } from '../../api/types';
import { ago, clock, count, ms, percent, statusTone } from '../../lib/format';
import { useGo } from '../../lib/links';
import { BootContext, DimensionValue } from '../DimensionValue';
import { Sparkline } from '../charts/Sparkline';

const CHIP_KEYS = ['user.id', 'client.address'];

/** Request/trace rows: time · status · target + dimension chips · duration. */
export function SpanList({ rows, selected, height = 640, onSelect }: { rows: SpanRow[]; selected?: string; height?: number; onSelect?: (row: SpanRow) => void }) {
    const scroller = useRef<HTMLDivElement>(null);
    const boot = useContext(BootContext);
    const go = useGo();
    const custom = (boot?.dimensions ?? []).filter((d) => !d.builtin).map((d) => d.key);

    const virtualizer = useVirtualizer({ count: rows.length, getScrollElement: () => scroller.current, estimateSize: () => 52, overscan: 16 });
    const items = virtualizer.getVirtualItems();
    const render = (row: SpanRow) => {
        const chips = [...custom, ...CHIP_KEYS].filter((k) => row.attributes[k]);
        return (
            <div
                className={`t-lrow ${selected === row.traceId ? 'is-sel' : ''} ${row.error ? 'is-error' : ''}`}
                role="button"
                tabIndex={0}
                onClick={(e) => {
                    if ((e.target as HTMLElement).closest('button:not(.t-lrow),a')) return;
                    if (onSelect) onSelect(row);
                    else go({ to: 'trace', id: row.traceId }, { replaceDrawer: true });
                }}
                onKeyDown={(e) => e.key === 'Enter' && go({ to: 'trace', id: row.traceId }, { replaceDrawer: true })}
            >
                <span className="t-lrow-time mono">{clock(row.startMs)}</span>
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
        <div className="t-list" ref={scroller} style={{ maxHeight: height }}>
            {items.length === 0 && rows.length > 0
                ? rows.slice(0, 60).map((r, i) => <div key={`${r.traceId}-${i}`}>{render(r)}</div>)
                : (
                    <div style={{ height: virtualizer.getTotalSize(), position: 'relative' }}>
                        {items.map((v) => (
                            <div key={v.key} style={{ position: 'absolute', top: 0, left: 0, right: 0, height: v.size, transform: `translateY(${v.start}px)` }}>
                                {render(rows[v.index]!)}
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
    return (
        <div className="t-list t-errlist">
            {rows.map((row) => (
                <div key={row.group} className="t-erow" role="button" tabIndex={0} onClick={() => go({ to: 'error', group: row.group }, { replaceDrawer: true })} onKeyDown={(e) => e.key === 'Enter' && go({ to: 'error', group: row.group }, { replaceDrawer: true })}>
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
export function GroupTable({ groupKey, groups, exact }: { groupKey: string; groups: Group[]; exact?: boolean }) {
    const max = Math.max(1, ...groups.map((g) => g.count));
    return (
        <div className="t-groups">
            <div className="t-groups-head">
                <span>{groupKey}</span>
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
                    <span className="is-num mono">{count(g.count)}</span>
                    <span className={`is-num mono ${g.errorRate > 0.01 ? 't-tone-danger' : 't-dim'}`}>{exact && g.errors === 0 ? '—' : percent(g.errorRate)}</span>
                    <span className="is-num mono">{ms(g.avg)}</span>
                    <span className="is-num mono">{ms(g.p95)}</span>
                </div>
            ))}
        </div>
    );
}
