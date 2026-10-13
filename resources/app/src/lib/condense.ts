import type { SpanData, WaterfallRow } from '../api/types';

/**
 * The condensed waterfall: runs of near-identical sibling spans (an N+1 of
 * 300 `db.query` spans, 500 cache reads) folded into one row each, so a
 * trace reads by what it did rather than by how many times it did it.
 *
 * Pure and linear in the span count: a 5,000-span trace condenses in well
 * under a frame (see condense.test.ts).
 */

/** The default minimum run length; hosts set `telemetry-ui.traces.waterfall.group_threshold`. */
export const DEFAULT_GROUP_THRESHOLD = 3;

export type RepeatKind = 'n_plus_one' | 'duplicate';

export interface SpanGroup {
    /** Stable for the trace: the first occurrence's span id. */
    id: string;
    key: string;
    name: string;
    service: string;
    /** What the occurrences share, shown beside the name (the SQL, the cache key group). */
    label: string | null;
    rows: WaterfallRow[];
    depth: number;
    ancestors: string[];
    totalMs: number;
    maxMs: number;
    /** Indices into `rows` of the occurrences that failed: grouping never hides an error. */
    errors: number[];
    /** laravel-telemetry 3.2's marker, on the threshold-crossing occurrence onward. N+1 wins over duplicate. */
    repeat: RepeatKind | null;
    /** `file:line` from the first occurrence that carries `code.*` (the crossing one, or a slow one). */
    origin: string | null;
    originFunction: string | null;
    /** The group's own extent, in percent of the trace. */
    startPct: number;
    endPct: number;
    /** The parent's extent, in percent of the trace: the mini-timeline's frame. */
    windowStartPct: number;
    windowEndPct: number;
}

export type CondensedItem = { kind: 'span'; row: WaterfallRow } | { kind: 'group'; group: SpanGroup };

/**
 * What makes two sibling spans "the same": the query's shape (laravel-telemetry
 * 3.2 stamps a hash of the normalised SQL on every query span), else its exact
 * text; a cache operation's store, operation and key group; a view by its
 * `view.name`, an outgoing call by method and `server.address`, a Redis
 * command by `db.operation.name`; else the name.
 * Service and kind are part of it, so a client span and the server span it
 * caused never merge.
 */
export function groupKey(span: SpanData): string {
    const a = span.attributes;
    const base = `${span.service}\u0000${span.kind}\u0000${span.name}`;

    const shape = a['laravel.db.query.shape'];
    if (shape) return `${base}\u0000shape\u0000${shape}`;

    const text = a['db.query.text'];
    if (text) return `${base}\u0000text\u0000${text}`;

    if (a['cache.operation'] !== undefined || a['cache.key.group'] !== undefined || span.name.startsWith('cache.')) {
        return `${base}\u0000cache\u0000${a['cache.store'] ?? ''}\u0000${a['cache.operation'] ?? ''}\u0000${a['cache.key.group'] ?? ''}`;
    }

    // Generic names that carry their identity in an attribute: a view render,
    // an outgoing call, a Redis command. Newer emitters put it in the name
    // too ("view orders.index", "GET api.stripe.com", "redis GET"); older
    // ones and other SDKs do not, and two views are never "the same".
    for (const keys of DISTINGUISHING) {
        if (keys.some((k) => a[k] !== undefined)) return `${base}\u0000${keys.map((k) => a[k] ?? '').join('\u0000')}`;
    }

    return base;
}

/** Attribute sets that tell apart spans sharing a generic name, checked in order. */
const DISTINGUISHING: string[][] = [
    ['view.name'],
    ['http.request.method', 'server.address'],
    ['db.operation.name', 'db.system.name'],
    ['messaging.destination.name'],
    ['laravel.job.class'],
];

function labelOf(span: SpanData): string | null {
    const a = span.attributes;
    if (a['db.query.text']) return a['db.query.text'];
    if (a['cache.key.group']) return a['cache.key.group'];
    if (a['cache.operation'] && !span.name.endsWith(a['cache.operation'])) return a['cache.operation'];
    const distinct = a['view.name'] ?? a['server.address'] ?? a['messaging.destination.name'] ?? a['laravel.job.class'];
    return distinct && !span.name.includes(distinct) ? distinct : null;
}

function parentOf(row: WaterfallRow): string | null {
    return row.ancestors.length > 0 ? row.ancestors[row.ancestors.length - 1]! : null;
}

function buildGroup(rows: WaterfallRow[], key: string, byId: Map<string, WaterfallRow>): SpanGroup {
    const first = rows[0]!;
    const parent = byId.get(parentOf(first) ?? '');
    let totalMs = 0;
    let maxMs = 0;
    let startPct = Infinity;
    let endPct = -Infinity;
    let repeat: RepeatKind | null = null;
    let origin: string | null = null;
    let originFunction: string | null = null;
    const errors: number[] = [];

    rows.forEach((row, i) => {
        const s = row.span;
        totalMs += s.durationMs;
        if (s.durationMs > maxMs) maxMs = s.durationMs;
        if (row.offsetPct < startPct) startPct = row.offsetPct;
        if (row.offsetPct + row.widthPct > endPct) endPct = row.offsetPct + row.widthPct;
        if (s.error) errors.push(i);

        const marker = s.attributes['laravel.db.query.repeat'];
        if (marker === 'n_plus_one') repeat = 'n_plus_one';
        else if (marker === 'duplicate' && repeat === null) repeat = 'duplicate';

        if (origin === null && s.attributes['code.file.path']) {
            const line = s.attributes['code.line.number'];
            origin = line ? `${s.attributes['code.file.path']}:${line}` : s.attributes['code.file.path'];
            originFunction = s.attributes['code.function.name'] ?? null;
        }
    });

    return {
        id: first.span.spanId,
        key,
        name: first.span.name,
        service: first.span.service,
        label: labelOf(first.span),
        rows,
        depth: first.depth,
        ancestors: first.ancestors,
        totalMs,
        maxMs,
        errors,
        repeat,
        origin,
        originFunction,
        startPct,
        endPct,
        windowStartPct: parent ? parent.offsetPct : 0,
        windowEndPct: parent ? parent.offsetPct + parent.widthPct : 100,
    };
}

/**
 * Fold every run of at least `threshold` consecutive sibling leaf spans with
 * the same {@link groupKey} into one group; everything else passes through
 * in waterfall order.
 *
 * Only leaves are grouped: a span with children keeps its subtree visible,
 * so nothing — an error least of all — ever nests out of sight inside a
 * group. Consecutive leaf siblings are adjacent in the depth-first order, so
 * one pass finds every run. A threshold under 2 turns grouping off.
 */
export function condense(rows: WaterfallRow[], threshold: number = DEFAULT_GROUP_THRESHOLD): CondensedItem[] {
    if (threshold < 2 || rows.length < threshold) return rows.map((row) => ({ kind: 'span', row }));

    const byId = new Map(rows.map((r) => [r.span.spanId, r]));
    const items: CondensedItem[] = [];
    let i = 0;

    while (i < rows.length) {
        const row = rows[i]!;
        if (row.children > 0) {
            items.push({ kind: 'span', row });
            i++;
            continue;
        }

        const key = groupKey(row.span);
        const parent = parentOf(row);
        let j = i + 1;
        while (j < rows.length) {
            const next = rows[j]!;
            if (next.children > 0 || next.depth !== row.depth || parentOf(next) !== parent || groupKey(next.span) !== key) break;
            j++;
        }

        if (j - i >= threshold) {
            items.push({ kind: 'group', group: buildGroup(rows.slice(i, j), key, byId) });
        } else {
            for (let k = i; k < j; k++) items.push({ kind: 'span', row: rows[k]! });
        }
        i = j;
    }

    return items;
}

/**
 * Occurrences bucketed across a frame (percent of the trace) for the
 * mini-timeline: at most `buckets` marks however many spans there are, and
 * a bucket holding a failed occurrence says so.
 */
export function timelineBuckets(rows: WaterfallRow[], fromPct: number, toPct: number, buckets: number): { at: number; count: number; error: boolean }[] {
    const width = Math.max(toPct - fromPct, 1e-9);
    const counts = new Map<number, { count: number; error: boolean }>();

    for (const row of rows) {
        const at = Math.min(buckets - 1, Math.max(0, Math.floor(((row.offsetPct - fromPct) / width) * buckets)));
        const b = counts.get(at) ?? { count: 0, error: false };
        b.count++;
        b.error ||= row.span.error;
        counts.set(at, b);
    }

    return [...counts.entries()].sort((a, b) => a[0] - b[0]).map(([at, b]) => ({ at, ...b }));
}
