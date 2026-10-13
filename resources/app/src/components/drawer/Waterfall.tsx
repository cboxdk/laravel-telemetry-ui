import { defaultRangeExtractor, useVirtualizer, type Range } from '@tanstack/react-virtual';
import { useContext, useEffect, useMemo, useRef, useState, type CSSProperties, type KeyboardEvent as ReactKeyboardEvent } from 'react';
import type { SpanData, TraceData, WaterfallRow } from '../../api/types';
import { condense, DEFAULT_GROUP_THRESHOLD, timelineBuckets, type CondensedItem, type SpanGroup } from '../../lib/condense';
import { ms, shortId } from '../../lib/format';
import { Go } from '../../lib/links';
import { useScrollParent } from '../../lib/scrollParent';
import { load, save } from '../../lib/storage';
import { BootContext, DimensionValue } from '../DimensionValue';
import { Icon } from '../Icon';

/** What a span does, for the bar colour: the waterfall reads by category at a glance. */
const CATEGORIES: { key: string; label: string; test: (a: Record<string, string>, s: SpanData) => boolean }[] = [
    // Before the db/cache tests, which a connect span would otherwise match:
    // a handshake is its own kind of wait, and the thing you are looking for
    // when the first query sits behind an unexplained gap.
    { key: 'connect', label: 'Connect', test: (_a, s) => s.name === 'db.connect' || s.name === 'redis.connect' },
    { key: 'db', label: 'Database', test: (a) => Boolean(a['db.system.name'] ?? a['db.system']) && !(a['db.system.name'] ?? a['db.system'] ?? '').includes('redis') },
    { key: 'cache', label: 'Cache / Redis', test: (a, s) => Boolean(a['cache.key'] ?? a['cache.store']) || (a['db.system.name'] ?? a['db.system'] ?? '') === 'redis' || s.name.startsWith('cache.') },
    { key: 'http', label: 'Outgoing HTTP', test: (a, s) => s.kind === 'client' && Boolean(a['server.address'] ?? a['url.full']) },
    { key: 'queue', label: 'Queue', test: (a) => Boolean(a['messaging.system'] ?? a['messaging.destination.name']) },
    { key: 'view', label: 'Views', test: (a, s) => Boolean(a['view.name']) || s.name.startsWith('view.') },
];

function categoryOf(s: SpanData): string {
    return CATEGORIES.find((c) => c.test(s.attributes, s))?.key ?? 'app';
}

/** The part of a span's summary that doesn't repeat its name ("GET /x → 200" → "→ 200"). */
function extraSummary(name: string, summary: string | null): string | null {
    if (!summary) return null;
    if (summary === name) return null;
    return summary.startsWith(name) ? summary.slice(name.length).trim() || null : summary;
}

/** "app/Models/Order.php:19" → "Models/Order.php:19". */
function shortOrigin(origin: string): string {
    return origin.split('/').slice(-2).join('/');
}

export type Layout = 'condensed' | 'full';

const LAYOUT_KEY = 'waterfall.layout';
/** Every row is one fixed height, so the virtualiser never measures. */
const ROW = 28;
/** Below this many rows the list renders plainly; above it only what is on screen. */
const VIRTUALIZE_FROM = 150;
/** What a virtualised list renders when it has nothing to measure against (no layout, a hidden tab). */
const FALLBACK_ROWS = 150;

type Line =
    | { kind: 'span'; row: WaterfallRow; occurrence?: { group: SpanGroup; index: number } }
    | { kind: 'group'; group: SpanGroup }
    | { kind: 'pager'; group: SpanGroup };

interface Open { index: number; all: boolean }

/**
 * The trace's spans on a timeline. Condensed by default: runs of
 * near-identical sibling spans (an N+1, a loop of cache reads) fold into one
 * row with their count, total and worst duration, where they fell inside
 * their parent, and the emitter's N+1 / duplicate marker with the line that
 * ran them. A group pages through its occurrences one at a time or shows
 * them all; failed occurrences are counted on the row and one click away.
 * The full layout is the flat waterfall, one row per span.
 */
export function Waterfall({ data, threshold: thresholdProp }: { data: TraceData; threshold?: number }) {
    const boot = useContext(BootContext);
    const threshold = thresholdProp ?? boot?.traces?.groupThreshold ?? DEFAULT_GROUP_THRESHOLD;
    const [layout, setLayoutState] = useState<Layout>(() => (load<string>(LAYOUT_KEY, 'condensed') === 'full' ? 'full' : 'condensed'));
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
    const [open, setOpen] = useState<Map<string, Open>>(new Map());
    const [selected, setSelected] = useState<string | null>(null);
    const [focusPager, setFocusPager] = useState<string | null>(null);
    const [focusGroup, setFocusGroup] = useState<string | null>(null);
    const [revealId, setRevealId] = useState<string | null>(null);
    const listRef = useRef<HTMLDivElement>(null);
    const scroll = useScrollParent(listRef);

    const setLayout = (next: Layout) => {
        setLayoutState(next);
        save(LAYOUT_KEY, next);
        // Groups do not exist in the other layout: an open one, and the
        // "occurrence n of N" it gave the selection, would linger otherwise.
        setOpen(new Map());
    };

    const spans = useMemo(() => new Map(data.waterfall.map((r) => [r.span.spanId, r.span])), [data.waterfall]);
    const used = useMemo(() => new Set(data.waterfall.map((r) => categoryOf(r.span))), [data.waterfall]);
    const condensed = useMemo(() => condense(data.waterfall, threshold), [data.waterfall, threshold]);
    const items: CondensedItem[] = useMemo(
        () => (layout === 'condensed' ? condensed : data.waterfall.map((row) => ({ kind: 'span' as const, row }))),
        [layout, condensed, data.waterfall],
    );
    const groups = useMemo(() => condensed.filter((i): i is { kind: 'group'; group: SpanGroup } => i.kind === 'group'), [condensed]);
    const folded = groups.reduce((n, g) => n + g.group.rows.length, 0);

    const lines = useMemo(() => {
        const out: Line[] = [];
        for (const item of items) {
            const ancestors = item.kind === 'span' ? item.row.ancestors : item.group.ancestors;
            if (collapsed.size > 0 && ancestors.some((a) => collapsed.has(a))) continue;
            if (item.kind === 'span') {
                out.push(item);
                continue;
            }

            const group = item.group;
            out.push({ kind: 'group', group });
            const state = open.get(group.id);
            if (!state) continue;

            out.push({ kind: 'pager', group });
            if (state.all) group.rows.forEach((row, index) => out.push({ kind: 'span', row, occurrence: { group, index } }));
            else out.push({ kind: 'span', row: group.rows[state.index]!, occurrence: { group, index: state.index } });
        }
        return out;
    }, [items, collapsed, open]);

    const virtual = lines.length > VIRTUALIZE_FROM;
    // Open groups' pagers stay mounted however far they scroll away: the one
    // that has the keyboard must not lose focus to the virtualiser.
    const pinned = useMemo(() => lines.flatMap((l, i) => (l.kind === 'pager' ? [i] : [])), [lines]);
    const virtualizer = useVirtualizer({
        count: virtual ? lines.length : 0,
        getScrollElement: () => scroll.element,
        estimateSize: () => ROW,
        overscan: 24,
        scrollMargin: scroll.margin,
        // The sticky span-detail panel covers the bottom of the scroll box.
        scrollPaddingEnd: 240,
        rangeExtractor: (range: Range) => (pinned.length === 0 ? defaultRangeExtractor(range) : [...new Set([...defaultRangeExtractor(range), ...pinned])].sort((a, b) => a - b)),
    });

    const focusData = (attribute: 'pager' | 'group', id: string) => {
        const els = listRef.current?.querySelectorAll<HTMLElement>(`[data-${attribute}]`) ?? [];
        [...els].find((el) => el.dataset[attribute] === id)?.focus({ preventScroll: true });
    };

    // A pager that just opened takes the keyboard, so ← → step at once; a
    // group closed from its pager gives the keyboard back to its row.
    useEffect(() => {
        if (focusPager === null) return;
        focusData('pager', focusPager);
        setFocusPager(null);
    }, [focusPager, lines]);

    useEffect(() => {
        if (focusGroup === null) return;
        focusData('group', focusGroup);
        setFocusGroup(null);
    }, [focusGroup, lines]);

    // Bring a stepped-to occurrence into view, from the lines as they are
    // after the step rendered — centred, so the sticky span detail at the
    // bottom does not cover it.
    useEffect(() => {
        if (revealId === null) return;
        const at = lines.findIndex((l) => l.kind === 'span' && l.row.span.spanId === revealId);
        if (at !== -1) {
            if (virtual) virtualizer.scrollToIndex(at, { align: 'center' });
            else {
                const el = listRef.current?.querySelector<HTMLElement>(`[data-span="${revealId}"]`);
                if (el && typeof el.scrollIntoView === 'function') el.scrollIntoView({ block: 'center' });
            }
        }
        setRevealId(null);
    }, [revealId, lines, virtual, virtualizer]);

    const openGroup = (group: SpanGroup, index: number, all?: boolean) => {
        const at = Math.max(0, Math.min(group.rows.length - 1, index));
        setOpen((o) => new Map(o).set(group.id, { index: at, all: all ?? o.get(group.id)?.all ?? false }));
        setSelected(group.rows[at]!.span.spanId);
        setFocusPager(group.id);
        setRevealId(group.rows[at]!.span.spanId);
    };

    const closeGroup = (group: SpanGroup, refocus = false) => {
        setOpen((o) => {
            const n = new Map(o);
            n.delete(group.id);
            return n;
        });
        if (selected !== null && group.rows.some((r) => r.span.spanId === selected)) setSelected(null);
        if (refocus) setFocusGroup(group.id);
    };

    const nextError = (group: SpanGroup, from: number) => group.errors.find((e) => e > from) ?? group.errors[0] ?? from;

    const onPagerKey = (group: SpanGroup, index: number) => (e: ReactKeyboardEvent<HTMLElement>) => {
        const last = group.rows.length - 1;
        const to = e.key === 'ArrowRight' ? index + 1 : e.key === 'ArrowLeft' ? index - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? last : e.key === 'e' ? nextError(group, index) : null;
        if (to === null) {
            if (e.key === 'Escape') { e.stopPropagation(); closeGroup(group, true); }
            return;
        }
        e.preventDefault();
        openGroup(group, to);
    };

    const toggleCollapsed = (spanId: string) => setCollapsed((c) => {
        const n = new Set(c);
        if (n.has(spanId)) n.delete(spanId);
        else n.add(spanId);
        return n;
    });

    const selectedSpan = selected !== null ? spans.get(selected) : undefined;
    const toggleGroup = (group: SpanGroup) => (open.has(group.id) ? closeGroup(group) : openGroup(group, 0));
    const selectedOccurrence = useMemo(() => {
        if (selected === null) return null;
        for (const id of open.keys()) {
            const group = groups.find((g) => g.group.id === id)?.group;
            const index = group?.rows.findIndex((r) => r.span.spanId === selected) ?? -1;
            if (group && index !== -1) return { group, index };
        }
        return null;
    }, [selected, open, groups]);

    const renderLine = (line: Line) => {
        if (line.kind === 'group') {
            return <GroupLine group={line.group} open={open.has(line.group.id)} onToggle={() => toggleGroup(line.group)} onError={() => openGroup(line.group, line.group.errors[0] ?? 0)} />;
        }
        if (line.kind === 'pager') {
            const state = open.get(line.group.id) ?? { index: 0, all: false };
            return (
                <PagerLine
                    group={line.group}
                    state={state}
                    onStep={(to) => openGroup(line.group, to)}
                    onNextError={() => openGroup(line.group, nextError(line.group, state.index))}
                    onAll={() => openGroup(line.group, state.index, !state.all)}
                    onKey={onPagerKey(line.group, state.index)}
                />
            );
        }
        const s = line.row.span;
        return (
            <SpanLine
                row={line.row}
                identityColor={data.identities[s.service]?.color}
                selected={selected === s.spanId}
                collapsed={collapsed.has(s.spanId)}
                occurrence={line.occurrence}
                onSelect={() => {
                    if (line.occurrence) openGroup(line.occurrence.group, line.occurrence.index);
                    else setSelected(selected === s.spanId ? null : s.spanId);
                }}
                onToggle={() => toggleCollapsed(s.spanId)}
            />
        );
    };

    const keyOf = (line: Line) => (line.kind === 'span' ? `s:${line.row.span.spanId}` : `${line.kind}:${line.group.id}`);
    const virtualItems = virtual ? virtualizer.getVirtualItems() : [];
    const ticks = [0, 0.25, 0.5, 0.75, 1];

    return (
        <div className="t-waterfall-wrap">
            <div className="t-wf-top">
                {used.size > 1 && (
                    <div className="t-wf-legend">
                        {[{ key: 'app', label: 'App' }, ...CATEGORIES].filter((c) => used.has(c.key)).map((c) => (
                            <span key={c.key}><i className={`t-wf-c-${c.key}`} />{c.label}</span>
                        ))}
                    </div>
                )}
                <div className="t-wf-layout">
                    {groups.length > 0 && layout === 'condensed' && (
                        <span className="t-dim" title={`Runs of ${threshold} or more identical sibling spans are folded into one row`}>
                            {groups.length} group{groups.length === 1 ? '' : 's'} · {folded} spans
                        </span>
                    )}
                    <div className="t-seg" role="group" aria-label="Waterfall layout">
                        <button type="button" className={layout === 'condensed' ? 'is-on' : ''} aria-pressed={layout === 'condensed'} onClick={() => setLayout('condensed')} title="Fold runs of identical sibling spans into one row">Condensed</button>
                        <button type="button" className={layout === 'full' ? 'is-on' : ''} aria-pressed={layout === 'full'} onClick={() => setLayout('full')} title="One row per span">Full</button>
                    </div>
                </div>
            </div>
            <div className="t-waterfall" role="tree" aria-label="Spans">
                <div className="t-wf-row t-wf-head" aria-hidden="true">
                    <div className="t-wf-name"><span className="t-eyebrow">Span</span></div>
                    <div className="t-wf-track t-wf-ruler">
                        {ticks.map((t) => (
                            <span key={t} className="t-wf-tick" style={t === 1 ? { right: 4 } : { left: `calc(${t * 100}% + 4px)` }}>{ms(data.durationMs * t)}</span>
                        ))}
                    </div>
                </div>
                <div ref={listRef} className="t-wf-lines">
                    {!virtual
                        ? lines.map((line) => <div key={keyOf(line)}>{renderLine(line)}</div>)
                        : virtualItems.length === 0
                            ? lines.slice(0, FALLBACK_ROWS).map((line) => <div key={keyOf(line)}>{renderLine(line)}</div>)
                            : (
                                <div style={{ height: virtualizer.getTotalSize(), position: 'relative' }}>
                                    {virtualItems.map((v) => {
                                        const line = lines[v.index]!;
                                        return (
                                            <div key={keyOf(line)} className="t-wf-vrow" style={{ height: ROW, transform: `translateY(${v.start - scroll.margin}px)` }}>
                                                {renderLine(line)}
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                </div>
            </div>
            {selectedSpan && (
                <SpanDetail
                    span={selectedSpan}
                    links={data.dimensionLinks}
                    occurrence={selectedOccurrence ? `Occurrence ${selectedOccurrence.index + 1} of ${selectedOccurrence.group.rows.length}` : null}
                    onClose={() => setSelected(null)}
                />
            )}
        </div>
    );
}

function durationStyle(offsetPct: number, widthPct: number): { style: CSSProperties; inside: boolean } {
    const end = offsetPct + widthPct;
    // Duration label beside the bar: after it, else before it, else inside.
    const style = end < 78
        ? { left: `calc(${end}% + 4px)` }
        : offsetPct > 18
            ? { right: `calc(${100 - offsetPct}% + 4px)` }
            : { right: `calc(${100 - end}% + 4px)` };
    return { style, inside: end >= 78 && offsetPct <= 18 };
}

function SpanLine({ row, identityColor, selected, collapsed, occurrence, onSelect, onToggle }: {
    row: WaterfallRow;
    identityColor: string | undefined;
    selected: boolean;
    collapsed: boolean;
    occurrence?: { group: SpanGroup; index: number };
    onSelect: () => void;
    onToggle: () => void;
}) {
    const s = row.span;
    const { style, inside } = durationStyle(row.offsetPct, row.widthPct);
    const extra = extraSummary(s.name, s.summary);
    return (
        <div
            className={`t-wf-row ${s.error ? 'is-error' : ''} ${selected ? 'is-sel' : ''} ${occurrence ? 'is-occurrence' : ''}`}
            role="treeitem"
            aria-selected={selected}
            data-span={s.spanId}
            onClick={onSelect}
        >
            <div className="t-wf-name" style={{ paddingLeft: 6 + (row.depth + (occurrence ? 1 : 0)) * 12 }}>
                {row.children > 0 ? (
                    <button type="button" className="t-wf-toggle" aria-label={collapsed ? 'Expand' : 'Collapse'} onClick={(e) => { e.stopPropagation(); onToggle(); }}>
                        <Icon name={collapsed ? 'chevronRight' : 'chevronDown'} size={11} />
                    </button>
                ) : <span className="t-wf-toggle" />}
                <span className="t-wf-dot" style={{ background: identityColor }} title={s.service} />
                {occurrence && <span className="t-wf-nth mono">#{occurrence.index + 1}</span>}
                {s.browser && <span className="t-badge t-badge-warn">web</span>}
                <span className="mono t-ellipsis" title={s.summary ?? s.name}>{s.name}</span>
                {extra && <span className="t-wf-summary mono t-ellipsis">{extra}</span>}
            </div>
            <div className="t-wf-track">
                <span className={`t-wf-bar t-wf-c-${categoryOf(s)}`} style={{ left: `${row.offsetPct}%`, width: `${row.widthPct}%` }} />
                <span className={`t-wf-dur mono ${inside ? 'is-inside' : ''}`} style={style}>{ms(s.durationMs)}</span>
            </div>
        </div>
    );
}

/** Where a group's occurrences fell inside their parent: one mark per bucket, red where one failed. */
function MiniTimeline({ group }: { group: SpanGroup }) {
    const width = 64;
    const height = 12;
    const buckets = timelineBuckets(group.rows, group.windowStartPct, group.windowEndPct, width / 2);
    const max = Math.max(...buckets.map((b) => b.count), 1);
    return (
        <svg className="t-wf-mini" width={width} height={height} aria-hidden="true">
            <rect x={0} y={height - 1} width={width} height={1} className="t-wf-mini-base" />
            {buckets.map((b) => {
                const h = Math.max(2, (b.count / max) * (height - 1));
                return <rect key={b.at} x={b.at * 2} y={height - 1 - h} width={1.5} height={h} className={b.error ? 't-wf-mini-err' : 't-wf-mini-mark'} />;
            })}
        </svg>
    );
}

function GroupLine({ group, open, onToggle, onError }: { group: SpanGroup; open: boolean; onToggle: () => void; onError: () => void }) {
    const n = group.rows.length;
    const first = group.rows[0]!.span;
    const { style, inside } = durationStyle(group.startPct, group.endPct - group.startPct);
    // Occurrences at trace scale, bucketed so a group of 5,000 is still ~100 marks.
    const marks = timelineBuckets(group.rows, 0, 100, 200);
    const title = [
        `${n}× ${group.name}`,
        group.label,
        `total ${ms(group.totalMs)} · max ${ms(group.maxMs)}`,
        group.origin ? `from ${group.origin}${group.originFunction ? ` · ${group.originFunction}` : ''}` : null,
    ].filter(Boolean).join('\n');

    return (
        <div
            className={`t-wf-row t-wf-group ${group.errors.length > 0 ? 'has-error' : ''} ${open ? 'is-open' : ''}`}
            role="treeitem"
            aria-expanded={open}
            aria-label={`${group.name}, ${n} occurrences`}
            title={title}
            tabIndex={0}
            data-group={group.id}
            onClick={onToggle}
            onKeyDown={(e) => {
                // A row you can reach with Tab opens like a button.
                if (e.target !== e.currentTarget) return;
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onToggle(); }
            }}
        >
            <div className="t-wf-name" style={{ paddingLeft: 6 + group.depth * 12 }}>
                <span className="t-wf-toggle"><Icon name={open ? 'chevronDown' : 'chevronRight'} size={11} /></span>
                <span className="t-wf-dot t-wf-dot-stack" />
                <span className="mono t-wf-gname">{group.name}</span>
                <span className="t-badge t-badge-info t-wf-count">×{n}</span>
                {group.repeat && <span className="t-badge t-badge-warn">{group.repeat === 'n_plus_one' ? 'N+1' : 'duplicate'}</span>}
                {group.errors.length > 0 && (
                    <button type="button" className="t-badge t-badge-danger t-wf-errbtn" title="Show the first failed occurrence" onClick={(e) => { e.stopPropagation(); onError(); }}>
                        {group.errors.length} failed
                    </button>
                )}
                {group.origin && <span className="mono t-dim t-wf-origin">{shortOrigin(group.origin)}</span>}
                {group.label && <span className="t-wf-summary mono t-ellipsis">{group.label}</span>}
                <MiniTimeline group={group} />
            </div>
            <div className="t-wf-track">
                <span className={`t-wf-extent t-wf-c-${categoryOf(first)}`} style={{ left: `${group.startPct}%`, width: `${Math.max(group.endPct - group.startPct, 0.35)}%` }} />
                <svg className="t-wf-marks" viewBox="0 0 200 10" preserveAspectRatio="none" aria-hidden="true">
                    {marks.map((m) => <rect key={m.at} x={m.at} y={0} width={0.8} height={10} className={m.error ? 't-wf-mini-err' : `t-wf-mark-${categoryOf(first)}`} />)}
                </svg>
                <span className={`t-wf-dur mono ${inside ? 'is-inside' : ''}`} style={style}>Σ {ms(group.totalMs)} · max {ms(group.maxMs)}</span>
            </div>
        </div>
    );
}

function PagerLine({ group, state, onStep, onNextError, onAll, onKey }: {
    group: SpanGroup;
    state: Open;
    onStep: (index: number) => void;
    onNextError: () => void;
    onAll: () => void;
    onKey: (e: ReactKeyboardEvent<HTMLElement>) => void;
}) {
    const n = group.rows.length;
    return (
        <div className="t-wf-row t-wf-pager" onClick={(e) => e.stopPropagation()}>
            <div
                className="t-wf-name t-wf-pager-ctl"
                style={{ paddingLeft: 6 + (group.depth + 1) * 12 }}
                role="group"
                aria-label={`Occurrences of ${group.name}: ← → to step, Home / End, e for the next failure`}
                tabIndex={0}
                data-pager={group.id}
                onKeyDown={onKey}
            >
                <button type="button" className="t-iconbtn t-wf-step" aria-label="Previous occurrence" disabled={state.index === 0} onClick={() => onStep(state.index - 1)}><Icon name="chevronLeft" size={12} /></button>
                <span className="mono t-wf-pos">{state.index + 1} of {n}</span>
                <button type="button" className="t-iconbtn t-wf-step" aria-label="Next occurrence" disabled={state.index >= n - 1} onClick={() => onStep(state.index + 1)}><Icon name="chevronRight" size={12} /></button>
                {group.errors.length > 0 && <button type="button" className="t-linkbtn t-wf-pager-err" onClick={onNextError}>Next failure</button>}
                <button type="button" className="t-linkbtn" onClick={onAll}>{state.all ? 'One at a time' : `Show all ${n}`}</button>
            </div>
            <div className="t-wf-track t-wf-pager-info">
                {group.origin && <span className="mono t-dim t-ellipsis" title={group.originFunction ?? undefined}>from {group.origin}</span>}
            </div>
        </div>
    );
}

/**
 * The phases inside an outgoing HTTP call: DNS, connection setup, waiting on
 * the far end, and the response coming back.
 *
 * The span says a call took 284ms. It does not say whether that was a name
 * lookup, a TLS handshake or the server thinking — and those have entirely
 * different fixes. cURL measures it already and the emitter records it; this
 * is the only place it is legible.
 *
 * Widths are relative to the sum of the phases, NOT to the span. Guzzle
 * follows redirects itself, so the phases describe the LAST hop while the
 * span covers them all: normalising against the span would silently shrink
 * every segment on a redirected call and read as a fast request.
 */
const HTTP_PHASES: { attr: string; label: string; hint: string }[] = [
    { attr: 'http.client.dns_ms', label: 'DNS', hint: 'Name lookup' },
    { attr: 'http.client.tcp_ms', label: 'TCP', hint: 'Connection setup' },
    { attr: 'http.client.tls_ms', label: 'TLS', hint: 'Handshake — through a proxy this includes the tunnel' },
    { attr: 'http.client.connect_ms', label: 'Connect', hint: 'QUIC does its handshake inside the transport, so setup is one number' },
    { attr: 'http.client.ttfb_ms', label: 'Waiting', hint: 'Sending the request body and waiting for the first response headers' },
    { attr: 'http.client.transfer_ms', label: 'Download', hint: 'Response body' },
];

function TransferPhases({ attrs, durationMs }: { attrs: Record<string, string>; durationMs: number }) {
    const phases = HTTP_PHASES
        .map((p) => ({ ...p, ms: Number(attrs[p.attr]) }))
        .filter((p) => Number.isFinite(p.ms));

    if (phases.length === 0) return null;

    const total = phases.reduce((sum, p) => sum + p.ms, 0);
    if (total <= 0) return null;

    const reused = attrs['http.client.connection_reused'] === 'true';
    // A gap means redirects: the span covers every hop, the phases the last.
    const unaccounted = durationMs - total;

    return (
        <div className="t-phases">
            <div className="t-eyebrow">Transfer phases</div>
            <div className="t-phasebar">
                {phases.map((p) => (
                    <span
                        key={p.attr}
                        className={`t-phase t-phase-${p.label.toLowerCase()}`}
                        style={{ width: `${(p.ms / total) * 100}%` }}
                        title={`${p.label} — ${p.hint}`}
                    />
                ))}
            </div>
            <dl className="t-phaselist">
                {phases.map((p) => (
                    <div key={p.attr} className="t-phaselist-row">
                        <dt><i className={`t-phase t-phase-${p.label.toLowerCase()}`} />{p.label}</dt>
                        <dd className="mono">{ms(p.ms)}</dd>
                    </div>
                ))}
            </dl>
            {reused && <p className="t-note">Connection reused — no lookup or handshake was needed.</p>}
            {unaccounted > total * 0.25 && unaccounted > 5 && (
                <p className="t-note">Phases describe the final hop ({ms(total)}); the span covers the whole call including redirects.</p>
            )}
        </div>
    );
}

function SpanDetail({ span, links, occurrence, onClose }: { span: SpanData; links: Record<string, string>; occurrence: string | null; onClose: () => void }) {
    return (
        <div className="t-spandetail">
            <div className="t-spandetail-head">
                <strong className="mono">{span.name}</strong>
                <span className="t-dim mono">{span.kind} · {span.service} · {ms(span.durationMs)}</span>
                {occurrence && <span className="t-pill">{occurrence}</span>}
                <button type="button" className="t-iconbtn" aria-label="Close span details" onClick={onClose}><Icon name="x" size={13} /></button>
            </div>
            {span.links.length > 0 && (
                <div className="t-chips">{span.links.map((l) => <Go key={l.spanId} link={{ to: 'trace', id: l.traceId }} className="t-minchip">linked {shortId(l.traceId)}</Go>)}</div>
            )}
            {span.attributes['code.file.path'] && (
                <p className="t-note mono" title={span.attributes['code.function.name'] ?? undefined}>
                    from {span.attributes['code.file.path']}{span.attributes['code.line.number'] ? `:${span.attributes['code.line.number']}` : ''}
                    {span.attributes['code.function.name'] ? ` · ${span.attributes['code.function.name']}` : ''}
                </p>
            )}
            {span.attributes['laravel.db.query.repeat'] && (
                <p className="t-note">{span.attributes['laravel.db.query.repeat'] === 'n_plus_one' ? 'Part of an N+1: this query shape ran repeatedly with different values.' : 'An identical repeat: the same SQL and bindings ran earlier in this trace.'}</p>
            )}
            {span.attributes['laravel.http.client.repeat'] === 'n_plus_one' && (
                <p className="t-note">Part of an N+1: this endpoint{span.attributes['url.template'] ? <> (<code>{span.attributes['url.template']}</code>)</> : null} was called one after another with different values. A batch endpoint, or Http::pool, sends them together.</p>
            )}
            <TransferPhases attrs={span.attributes} durationMs={span.durationMs} />
            <dl className="t-kv t-raw">
                {Object.entries(span.attributes).map(([k, v]) => (
                    <div key={k} className="t-kv-row"><dt className="mono">{k}</dt><dd><DimensionValue dimKey={k} value={v} linkOut={links[k]} /></dd></div>
                ))}
            </dl>
        </div>
    );
}
