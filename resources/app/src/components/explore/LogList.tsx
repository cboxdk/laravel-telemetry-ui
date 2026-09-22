import { useVirtualizer } from '@tanstack/react-virtual';
import { useContext, useMemo, useRef, useState } from 'react';
import type { LogEntryRow } from '../../api/types';
import { Go } from '../../lib/links';
import { BootContext, DimensionValue } from '../DimensionValue';

/** Loki labels are snake_cased OTLP keys; map them back to declared dimensions. */
function useLabelKeys(): Map<string, string> {
    const boot = useContext(BootContext);
    return useMemo(() => {
        const map = new Map<string, string>();
        for (const d of boot?.dimensions ?? []) map.set(d.key.replace(/[.-]/g, '_'), d.key);
        return map;
    }, [boot]);
}

function time(iso: string): string {
    const t = iso.indexOf('T');
    return t >= 0 ? iso.slice(t + 1, t + 13) : iso;
}

/** Virtualised log lines; click a line to expand its attributes. */
export function LogList({ rows, height = 560 }: { rows: LogEntryRow[]; height?: number }) {
    const scroller = useRef<HTMLDivElement>(null);
    const [open, setOpen] = useState<string | null>(null);
    const labelKeys = useLabelKeys();

    const virtualizer = useVirtualizer({
        count: rows.length,
        getScrollElement: () => scroller.current,
        estimateSize: () => 30,
        overscan: 20,
        measureElement: (el) => el.getBoundingClientRect().height,
    });

    const items = virtualizer.getVirtualItems();
    const fallback = items.length === 0 && rows.length > 0;

    const renderRow = (row: LogEntryRow, i: number) => {
        const key = `${row.nano ?? row.ms}-${i}`;
        const expanded = open === key;
        return (
            <div className={`t-log t-log-${row.tone} ${expanded ? 'is-open' : ''}`}>
                <button type="button" className="t-log-line" onClick={() => setOpen(expanded ? null : key)}>
                    <span className="t-log-time mono">{time(row.time)}</span>
                    <span className={`t-log-level t-tone-${row.tone}`}>{row.level.toUpperCase().slice(0, 5)}</span>
                    {row.service && <span className="t-log-service mono">{row.service}</span>}
                    <span className="t-log-msg mono">{row.message}</span>
                </button>
                {expanded && (
                    <div className="t-log-detail">
                        {row.traceId && <Go link={{ to: 'trace', id: row.traceId }} className="t-btn t-btn-sm t-btn-secondary">Open trace</Go>}
                        <div className="t-log-labels">
                            {Object.entries(row.labels).map(([k, v]) => {
                                const dim = labelKeys.get(k) ?? k;
                                return <DimensionValue key={k} dimKey={dim} value={v} chip label />;
                            })}
                        </div>
                        <pre className="t-log-full mono">{row.message}</pre>
                    </div>
                )}
            </div>
        );
    };

    return (
        <div className="t-loglist" ref={scroller} style={{ maxHeight: height }}>
            {fallback ? (
                rows.slice(0, 100).map((row, i) => <div key={i}>{renderRow(row, i)}</div>)
            ) : (
                <div style={{ height: virtualizer.getTotalSize(), position: 'relative' }}>
                    {items.map((v) => (
                        <div key={v.key} data-index={v.index} ref={virtualizer.measureElement} style={{ position: 'absolute', top: 0, left: 0, right: 0, transform: `translateY(${v.start}px)` }}>
                            {renderRow(rows[v.index]!, v.index)}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
