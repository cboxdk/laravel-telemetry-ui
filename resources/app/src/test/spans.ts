import type { SpanData, TraceData, WaterfallRow } from '../api/types';

/**
 * Build waterfall rows the way the API lays them out (depth-first, offsets in
 * percent of the trace), from a compact tree. Shared by the condense and
 * waterfall specs and the 5,000-span render benchmark.
 */
export interface SpanSpec {
    id?: string;
    name: string;
    durationMs?: number;
    error?: boolean;
    kind?: string;
    attributes?: Record<string, string>;
    children?: SpanSpec[];
}

let seq = 0;

export function span(name: string, attributes: Record<string, string> = {}, extra: Partial<SpanSpec> = {}): SpanSpec {
    return { name, attributes, ...extra };
}

export function rowsOf(root: SpanSpec, traceMs = 1000): WaterfallRow[] {
    const rows: WaterfallRow[] = [];
    // Children are laid end to end inside their parent, so offsets are real.
    const walk = (spec: SpanSpec, depth: number, ancestors: string[], startMs: number, durationMs: number, parent: string | null) => {
        const id = spec.id ?? `s${(++seq).toString(16).padStart(6, '0')}`;
        const s: SpanData = {
            spanId: id, parentSpanId: parent, name: spec.name, service: 'shop', kind: spec.kind ?? (depth === 0 ? 'server' : 'client'),
            startNano: String(BigInt(1735689600000 + Math.round(startMs)) * 1000000n), startMs: 1735689600000 + startMs, durationMs,
            error: spec.error ?? false, browser: false, summary: null, attributes: spec.attributes ?? {}, links: [],
        };
        const children = spec.children ?? [];
        rows.push({ span: s, depth, offsetPct: (startMs / traceMs) * 100, widthPct: Math.max(0.35, (durationMs / traceMs) * 100), ancestors, children: children.length });
        const slot = children.length > 0 ? durationMs / children.length : 0;
        children.forEach((child, i) => walk(child, depth + 1, [...ancestors, id], startMs + i * slot, child.durationMs ?? slot * 0.8, id));
    };
    walk(root, 0, [], 0, root.durationMs ?? traceMs, null);
    return rows;
}

export function traceOf(rows: WaterfallRow[], durationMs = 1000): TraceData {
    return {
        traceId: 'abc123abc123abc123abc123abc123ab',
        root: rows[0]?.span ?? null,
        durationMs,
        error: rows.some((r) => r.span.error),
        spanCount: rows.length,
        services: { shop: { 'service.name': 'shop' } },
        waterfall: rows,
        chain: [],
        identities: {},
        context: [],
        profile: [],
        report: { request: {}, requestHeaders: {}, responseHeaders: {}, totals: [], db: { items: [], duplicates: {} }, cache: { items: [], summary: {} }, redis: [], outgoing: [], queued: [], views: [], storage: [] },
        logs: [],
        exceptions: [],
        dimensionLinks: {},
    } as unknown as TraceData;
}

/** `n` copies of a span, built by `make(i)`. */
export function times(n: number, make: (i: number) => SpanSpec): SpanSpec[] {
    return Array.from({ length: n }, (_, i) => make(i));
}

/**
 * The benchmark trace: 5,000 spans shaped like a bad real request — a
 * controller with an N+1 of 1,200 queries, a loop of 1,500 cache reads, a
 * second N+1 nested under 40 jobs, and a partial rendered 1,057 times.
 */
export function bigTrace(): WaterfallRow[] {
    const shape = 'a1b2c3d4';
    const query = (i: number): SpanSpec => span('db.query', {
        'db.system.name': 'mysql',
        'db.query.text': 'select * from `orders` where `user_id` = ?',
        'laravel.db.query.shape': shape,
        ...(i >= 2 ? { 'laravel.db.query.repeat': 'n_plus_one' } : {}),
        ...(i === 2 ? { 'code.file.path': 'app/Http/Controllers/OrderController.php', 'code.line.number': '42' } : {}),
    }, { error: i === 700 });
    const cache = (): SpanSpec => span('cache.get', { 'cache.store': 'redis', 'cache.operation': 'get', 'cache.key.group': 'user:*' });
    const job = (j: number): SpanSpec => ({
        name: 'App\\Jobs\\SyncLine',
        children: times(30, (i) => span('db.query', { 'db.system.name': 'mysql', 'db.query.text': 'select * from `lines` where `id` = ?', 'laravel.db.query.shape': `l${j % 2}` }, { error: j === 7 && i === 3 })),
    });

    const tree: SpanSpec = {
        name: 'GET /orders',
        children: [
            { name: 'App\\Http\\Controllers\\OrderController@index', children: [...times(1200, query), ...times(1500, cache)] },
            { name: 'App\\Jobs\\Dispatcher', children: times(40, job) },
            ...times(5000 - 1 - 1 - 1200 - 1500 - 1 - 40 - 40 * 30, () => span('view partials.order-row', { 'view.name': 'partials.order-row' })),
        ],
    };
    return rowsOf(tree, 2000);
}
