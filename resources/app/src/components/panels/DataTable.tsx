import { useVirtualizer } from '@tanstack/react-virtual';
import { useMemo, useRef, useState } from 'react';
import type { Cell, Column, Link, Row, TicketDraft } from '../../api/types';
import { Go, useGo } from '../../lib/links';
import { DimensionValue } from '../DimensionValue';
import { Sparkline } from '../charts/Sparkline';
import { Icon } from '../Icon';

const VIRTUAL_AFTER = 80;
const ROW_H = 38;

function asCell(value: Row[string]): Cell | null {
    if (value === null || value === undefined) return null;
    if (typeof value === 'object' && 'v' in value) return value as Cell;
    if (typeof value === 'string' || typeof value === 'number') return { v: value };
    return null;
}

function sortValue(cell: Cell | null): number | string {
    if (!cell) return '';
    if (typeof cell.raw === 'number') return cell.raw;
    if (typeof cell.v === 'number') return cell.v;
    return String(cell.v ?? '').toLowerCase();
}

const TONES = new Set(['ok', 'warn', 'danger', 'dim', 'info']);

export function CellView({ cell, onParam }: { cell: Cell | null; onParam?: (p: Record<string, string>) => void }) {
    if (!cell) return <span className="t-dim">—</span>;

    const tone = cell.tone && TONES.has(cell.tone) ? cell.tone : undefined;
    let content: React.ReactNode = cell.v === null || cell.v === '' ? <span className="t-dim">—</span> : String(cell.v);

    if (cell.badge !== undefined && cell.badge !== '') {
        // `badge` is the badge's text; a bare tone name (legacy) means "badge the value".
        const text = TONES.has(cell.badge) ? String(cell.v ?? '') : cell.badge;
        const badgeTone = TONES.has(cell.badge) ? cell.badge : tone;
        content = (
            <>
                {text !== String(cell.v ?? '') && cell.v !== null && cell.v !== '' ? <span className="t-cell-text">{String(cell.v)}</span> : null}
                <span className={`t-badge ${badgeTone ? `t-badge-${badgeTone}` : ''}`}>{text}</span>
            </>
        );
    }

    if (cell.badges && cell.badges.length > 0) {
        content = <span className="t-badges">{cell.badges.map((b, i) => <span key={i} className={`t-badge ${b.tone ? `t-badge-${b.tone}` : ''}`}>{b.label}</span>)}</span>;
    }

    if (cell.dim && cell.v !== null && cell.v !== '') {
        content = <DimensionValue dimKey={cell.dim.key} value={cell.dim.value}>{content}</DimensionValue>;
    } else if (cell.link) {
        content = <Go link={cell.link} onParam={onParam} className="t-cell-link">{content}</Go>;
    }

    return (
        <div className={`t-cell ${tone && !cell.badge ? `t-tone-${tone}` : ''} ${cell.mono ? 'mono' : ''}`}>
            {cell.spark && cell.spark.length > 0 ? <Sparkline points={cell.spark} tone={tone ?? null} /> : content}
            {cell.bar !== undefined && <span className="t-cellbar"><i style={{ width: `${Math.max(1, Math.min(100, cell.bar * 100))}%` }} /></span>}
            {cell.sub && <span className="t-cell-sub">{cell.sub}</span>}
        </div>
    );
}

/**
 * The panel table: whole-row drill-down (`_link`), client-side sort by the
 * cell's raw value, and virtualised rendering for long lists.
 */
export function DataTable({ columns, rows, onParam, onTicket, maxHeight = 520 }: {
    columns: Column[];
    rows: Row[];
    onParam?: (p: Record<string, string>) => void;
    onTicket?: (draft: TicketDraft) => void;
    maxHeight?: number;
}) {
    const [sort, setSort] = useState<{ key: string; dir: 1 | -1 } | null>(null);
    const go = useGo();
    const scroller = useRef<HTMLDivElement>(null);

    const sorted = useMemo(() => {
        if (!sort) return rows;
        return [...rows].sort((a, b) => {
            const x = sortValue(asCell(a[sort.key]));
            const y = sortValue(asCell(b[sort.key]));
            return (x < y ? -1 : x > y ? 1 : 0) * sort.dir;
        });
    }, [rows, sort]);

    const virtual = sorted.length > VIRTUAL_AFTER;
    const virtualizer = useVirtualizer({
        count: sorted.length,
        getScrollElement: () => scroller.current,
        estimateSize: () => ROW_H,
        overscan: 12,
        enabled: virtual,
    });

    const hasTicket = rows.some((r) => r._ticket);
    const template = columns.map((c) => c.width ?? (c.align === 'right' ? 'minmax(64px, max-content)' : 'minmax(90px, 1fr)')).join(' ') + (hasTicket ? ' 36px' : '');

    const onRow = (link: Link | undefined, e: React.MouseEvent) => {
        if (!link) return;
        if ((e.target as HTMLElement).closest('a,button')) return;
        go(link, { newTab: e.metaKey || e.ctrlKey });
    };

    const renderRow = (row: Row, index: number, style?: React.CSSProperties) => (
        <div
            key={index}
            role="row"
            className={`t-tr ${row._link ? 'is-link' : ''}`}
            style={{ gridTemplateColumns: template, ...style }}
            onClick={(e) => onRow(row._link, e)}
            tabIndex={row._link ? 0 : undefined}
            onKeyDown={(e) => { if (e.key === 'Enter' && row._link) go(row._link); }}
        >
            {columns.map((c) => (
                <div key={c.key} role="cell" className={`t-td ${c.align === 'right' ? 'is-num' : ''}`}>
                    <CellView cell={asCell(row[c.key])} onParam={onParam} />
                </div>
            ))}
            {hasTicket && (
                <div role="cell" className="t-td">
                    {row._ticket && onTicket && (
                        <button type="button" className="t-iconbtn" title="File an issue" onClick={(e) => { e.stopPropagation(); onTicket(row._ticket!); }}>
                            <Icon name="plus" size={13} />
                        </button>
                    )}
                </div>
            )}
        </div>
    );

    return (
        <div className="t-table" role="table">
            <div className="t-tr t-thead" role="row" style={{ gridTemplateColumns: template }}>
                {columns.map((c) => (
                    <button
                        type="button"
                        key={c.key}
                        role="columnheader"
                        className={`t-th ${c.align === 'right' ? 'is-num' : ''} ${sort?.key === c.key ? 'is-sorted' : ''}`}
                        onClick={() => setSort((s) => (s?.key === c.key ? (s.dir === -1 ? { key: c.key, dir: 1 } : null) : { key: c.key, dir: -1 }))}
                    >
                        {c.label}
                        {sort?.key === c.key && <span className="t-sort">{sort.dir === -1 ? '↓' : '↑'}</span>}
                    </button>
                ))}
                {hasTicket && <span />}
            </div>
            <div className="t-tbody" ref={scroller} style={{ maxHeight }}>
                {virtual ? (
                    <div style={{ height: virtualizer.getTotalSize(), position: 'relative' }}>
                        {virtualizer.getVirtualItems().map((v) =>
                            renderRow(sorted[v.index]!, v.index, { position: 'absolute', top: 0, left: 0, right: 0, transform: `translateY(${v.start}px)`, height: ROW_H }),
                        )}
                    </div>
                ) : (
                    sorted.map((row, i) => renderRow(row, i))
                )}
            </div>
        </div>
    );
}
