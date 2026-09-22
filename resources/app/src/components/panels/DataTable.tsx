import { useVirtualizer } from '@tanstack/react-virtual';
import { useContext, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type { Cell, Column, Link, Row, TicketDraft } from '../../api/types';
import { Go, useGo } from '../../lib/links';
import { useScrollParent } from '../../lib/scrollParent';
import { BootContext, DimensionValue } from '../DimensionValue';
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
        content = <DimensionValue dimKey={cell.dim.key} value={cell.dim.value} link={cell.link} onParam={onParam}>{content}</DimensionValue>;
    } else if (cell.link) {
        content = <Go link={cell.link} onParam={onParam} className="t-cell-link">{content}</Go>;
    }

    return (
        <div className={`t-cell ${cell.sub ? 'has-sub' : ''} ${tone && !cell.badge ? `t-tone-${tone}` : ''} ${cell.mono ? 'mono' : ''}`}>
            <span className="t-cell-main">
                {cell.spark && cell.spark.length > 0 ? <Sparkline points={cell.spark} tone={tone ?? null} /> : content}
                {cell.bar !== undefined && <span className="t-cellbar"><i style={{ width: `${Math.max(1, Math.min(100, cell.bar * 100))}%` }} /></span>}
            </span>
            {cell.sub && <span className="t-cell-sub" title={cell.sub}>{cell.sub}</span>}
        </div>
    );
}

/**
 * Content-aware column widths: numbers take what they need, sparklines a fixed
 * track, badges hug their text, and the remaining space goes to text columns in
 * proportion to how long their values actually are — so a route or SQL column
 * isn't squeezed to the width of a method badge.
 */
export function columnTemplate(columns: Column[], rows: Row[], resolvable: ReadonlySet<string> = new Set()): string {
    const sample = rows.slice(0, 80);
    // Every row is its own grid, so tracks must be explicit — `max-content`
    // would size each row differently and the columns would not line up.
    const tracks = columns.map((c): { track: string; flexible: boolean; text: boolean; px: number } => {
        if (c.width) return { track: c.width, flexible: c.width.includes('fr'), text: false, px: 0 };

        const cells = sample.map((r) => asCell(r[c.key])).filter((x): x is Cell => x !== null);
        // Monospace digits/ids run wider than proportional text.
        const perChar = cells.some((x) => x.mono) || c.align === 'right' ? 7.9 : 7.2;
        const width = (chars: number, min: number, max: number) => Math.round(Math.min(max, Math.max(min, chars * perChar + 22)));
        const fixed = (n: number, text = false) => ({ track: `${n}px`, flexible: false, text, px: n });
        const text = (x: Cell) => String(x.v ?? '').length + (x.badge && !TONES.has(x.badge) && x.badge !== String(x.v ?? '') ? x.badge.length + 2 : 0);
        // Ids with a name resolver render as "Name id": leave room for the name.
        const named = cells.some((x) => x.dim && resolvable.has(x.dim.key));
        const longest = Math.max(c.label.length, ...cells.map(text)) + (named ? 16 : 0);
        const hasSub = cells.some((x) => x.sub);

        // A value plus its inline share bar needs room for both.
        if (cells.some((x) => x.bar !== undefined)) return fixed(width(longest + 9, 110, 170));
        if (c.align === 'right') return fixed(width(longest, 56, 150));
        if (cells.length > 0 && cells.every((x) => x.spark)) return fixed(96);
        if (hasSub) return { track: 'minmax(220px, 6fr)', flexible: true, text: true, px: 220 };

        const avg = cells.length === 0 ? 10 : cells.reduce((n, x) => n + String(x.v ?? '').length, 0) / cells.length;
        // Short values (ids, services, statuses) get exactly what they need, so
        // the free space goes to the long column (routes, SQL, messages).
        if (longest <= 22) return fixed(width(longest, 60, 190), true);
        const weight = Math.max(1, Math.min(8, Math.round(avg / 6)));
        return { track: `minmax(${avg > 24 ? 160 : 90}px, ${weight}fr)`, flexible: true, text: true, px: avg > 24 ? 160 : 90 };
    });

    // All fixed? Then the widest text column takes the free space instead of
    // leaving a blank strip at the right while it truncates.
    if (!tracks.some((t) => t.flexible)) {
        const widest = tracks.reduce<number>((best, t, i) => (t.text && (best < 0 || t.px > tracks[best]!.px) ? i : best), -1);
        if (widest >= 0) tracks[widest] = { ...tracks[widest]!, track: `minmax(${tracks[widest]!.px}px, 1fr)` };
    }

    return tracks.map((t) => t.track).join(' ');
}

/** The narrowest the grid template can get: px tracks + minmax() minimums + gaps + padding. */
export function templateMinWidth(template: string): number {
    const tracks = template.match(/minmax\([^)]*\)|\S+/g) ?? [];
    const widths = tracks.map((t) => Number(/(\d+(?:\.\d+)?)px/.exec(t)?.[1] ?? 40));
    return widths.reduce((a, b) => a + b, 0) + 12 * Math.max(0, tracks.length - 1) + 16;
}

function useWidth(ref: React.RefObject<HTMLElement | null>): number {
    const [width, setWidth] = useState(0);
    // Measure before paint (no flash of a table that then turns into cards),
    // then follow resizes.
    useLayoutEffect(() => {
        const el = ref.current;
        if (!el) return;
        setWidth(Math.round(el.getBoundingClientRect().width));
        if (typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(([entry]) => setWidth(Math.round(entry?.contentRect.width ?? 0)));
        observer.observe(el);
        return () => observer.disconnect();
    }, [ref]);
    return width;
}

const CARD_LIMIT = 60;

/**
 * The panel table: whole-row drill-down (`_link`), client-side sort by the
 * cell's raw value, and virtualised rendering for long lists.
 */
export function DataTable({ columns, rows, onParam, onTicket }: {
    columns: Column[];
    rows: Row[];
    onParam?: (p: Record<string, string>) => void;
    onTicket?: (draft: TicketDraft) => void;
}) {
    const [sort, setSort] = useState<{ key: string; dir: 1 | -1 } | null>(null);
    const go = useGo();
    const scroller = useRef<HTMLDivElement>(null);
    const table = useRef<HTMLDivElement>(null);
    const scroll = useScrollParent(scroller);
    const [allCards, setAllCards] = useState(false);

    const sorted = useMemo(() => {
        if (!sort) return rows;
        return [...rows].sort((a, b) => {
            const x = sortValue(asCell(a[sort.key]));
            const y = sortValue(asCell(b[sort.key]));
            return (x < y ? -1 : x > y ? 1 : 0) * sort.dir;
        });
    }, [rows, sort]);

    const hasTicket = rows.some((r) => r._ticket);
    const boot = useContext(BootContext);
    const resolvable = useMemo(() => new Set((boot?.dimensions ?? []).filter((d) => d.resolvable).map((d) => d.key)), [boot]);
    const template = useMemo(() => columnTemplate(columns, rows, resolvable), [columns, rows, resolvable]) + (hasTicket ? ' 36px' : '');
    // When the columns can't fit (phones, narrow panels) each row becomes a
    // card — first column as the title, the rest as labelled values — instead
    // of a table that pushes the page sideways.
    const width = useWidth(table);
    const cards = width > 0 && templateMinWidth(template) > width;

    const virtual = !cards && sorted.length > VIRTUAL_AFTER;
    const virtualizer = useVirtualizer({
        count: sorted.length,
        getScrollElement: () => scroll.element,
        estimateSize: () => ROW_H,
        overscan: 12,
        enabled: virtual,
        scrollMargin: scroll.margin,
    });

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

    return <div className="t-table-host" ref={table}>{cards ? renderCards() : renderTable()}</div>;

    function renderCards() {
        const [head, ...rest] = columns;
        const shown = allCards ? sorted : sorted.slice(0, CARD_LIMIT);
        return (
            <div className="t-table is-cards" role="table">
                {shown.map((row, i) => (
                    <div
                        key={i}
                        role="row"
                        className={`t-card-row ${row._link ? 'is-link' : ''}`}
                        onClick={(e) => onRow(row._link, e)}
                        tabIndex={row._link ? 0 : undefined}
                        onKeyDown={(e) => { if (e.key === 'Enter' && row._link) go(row._link); }}
                    >
                        {head && <div role="cell" className="t-card-title"><CellView cell={asCell(row[head.key])} onParam={onParam} /></div>}
                        <dl className="t-card-kv">
                            {rest.map((c) => {
                                const cell = asCell(row[c.key]);
                                if (!cell || cell.v === null || cell.v === '') return null;
                                return (
                                    <div key={c.key} role="cell" className="t-card-pair">
                                        <dt>{c.label}</dt>
                                        <dd className={c.align === 'right' ? 'is-num' : ''}><CellView cell={cell} onParam={onParam} /></dd>
                                    </div>
                                );
                            })}
                        </dl>
                        {row._ticket && onTicket && (
                            <button type="button" className="t-btn t-btn-sm t-btn-ghost t-card-ticket" onClick={(e) => { e.stopPropagation(); onTicket(row._ticket!); }}>
                                <Icon name="plus" size={12} />File an issue
                            </button>
                        )}
                    </div>
                ))}
                {!allCards && sorted.length > CARD_LIMIT && (
                    <button type="button" className="t-code-more" onClick={() => setAllCards(true)}>Show all {sorted.length} rows</button>
                )}
            </div>
        );
    }

    function renderTable() {
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
            {/* Long tables virtualise against the page scroll — no scroll box inside the panel. */}
            <div className="t-tbody" ref={scroller}>
                {virtual ? (
                    <div style={{ height: virtualizer.getTotalSize(), position: 'relative' }}>
                        {virtualizer.getVirtualItems().map((v) =>
                            renderRow(sorted[v.index]!, v.index, { position: 'absolute', top: 0, left: 0, right: 0, transform: `translateY(${v.start - scroll.margin}px)`, height: ROW_H }),
                        )}
                    </div>
                ) : (
                    sorted.map((row, i) => renderRow(row, i))
                )}
            </div>
        </div>
        );
    }
}
