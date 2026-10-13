import { describe, expect, it } from 'vitest';
import { bigTrace, rowsOf, span, times } from '../test/spans';
import { condense, groupKey, timelineBuckets, type SpanGroup } from './condense';

const groupsOf = (items: ReturnType<typeof condense>): SpanGroup[] => items.flatMap((i) => (i.kind === 'group' ? [i.group] : []));
const sql = 'select * from `users` where `users`.`id` = ? limit 1';

describe('condensing the waterfall', () => {
    it('folds an N+1 into one group with its count, total, worst, marker and origin', () => {
        // laravel-telemetry 3.5: the shape on every query, the marker from the
        // threshold-crossing occurrence onward, code.* on that one only.
        const rows = rowsOf({
            name: 'GET /orders',
            children: times(300, (i) => span('db.query', {
                'db.query.text': sql,
                'laravel.db.query.shape': 'f00d',
                ...(i >= 4 ? { 'laravel.db.query.repeat': 'n_plus_one' } : {}),
                ...(i === 4 ? { 'code.file.path': 'app/Models/Order.php', 'code.line.number': '19', 'code.function.name': 'App\\Models\\Order::lines' } : {}),
            }, { durationMs: i === 150 ? 9 : 1 })),
        });

        const items = condense(rows, 3);
        const [group] = groupsOf(items);

        expect(items).toHaveLength(2);
        expect(group!.rows).toHaveLength(300);
        expect(group!.totalMs).toBe(299 + 9);
        expect(group!.maxMs).toBe(9);
        expect(group!.repeat).toBe('n_plus_one');
        expect(group!.origin).toBe('app/Models/Order.php:19');
        expect(group!.originFunction).toBe('App\\Models\\Order::lines');
        expect(group!.label).toBe(sql);
        expect(group!.id).toBe(rows[1]!.span.spanId);
        // The frame of the mini-timeline is the parent's extent.
        expect(group!.windowStartPct).toBe(0);
        expect(group!.windowEndPct).toBe(100);
    });

    it('groups queries by shape, falling back to the exact text on older traces', () => {
        const rows = rowsOf({
            name: 'root',
            children: [
                ...times(3, () => span('db.query', { 'db.query.text': 'select 1 where id = 1', 'laravel.db.query.shape': 'aa' })),
                // Same shape, different literal text: still the same query.
                ...times(3, (i) => span('db.query', { 'db.query.text': `select 1 where id = ${i}`, 'laravel.db.query.shape': 'aa' })),
                ...times(3, () => span('db.query', { 'db.query.text': 'select 2', 'laravel.db.query.shape': 'bb' })),
                ...times(3, () => span('db.query', { 'db.query.text': 'select 3' })),
                ...times(3, (i) => span('db.query', { 'db.query.text': `select ${i}` })),
            ],
        });

        const groups = groupsOf(condense(rows, 3));
        expect(groups.map((g) => g.rows.length)).toEqual([6, 3, 3]);
        expect(groups.map((g) => g.label)).toEqual(['select 1 where id = 1', 'select 2', 'select 3']);
    });

    it('groups cache operations by store, operation and key group', () => {
        const get = (group?: string) => span('cache.get', { 'cache.store': 'redis', 'cache.operation': 'get', ...(group ? { 'cache.key.group': group } : {}), 'cache.key': `k${Math.random()}` });
        const rows = rowsOf({
            name: 'root',
            children: [...times(500, () => get()), ...times(4, () => get('user:*')), ...times(4, () => get('team:*'))],
        });

        const groups = groupsOf(condense(rows, 3));
        expect(groups.map((g) => g.rows.length)).toEqual([500, 4, 4]);
        expect(groups.map((g) => g.label)).toEqual([null, 'user:*', 'team:*']);
        expect(groupKey(rows[1]!.span)).not.toBe(groupKey(rows[501]!.span));
    });

    it('tells apart views, outgoing calls and Redis commands that share a generic name', () => {
        const rows = rowsOf({
            name: 'root',
            children: [
                // Older emitters and other SDKs name every render the same.
                ...times(3, () => span('view.render', { 'view.name': 'orders.row' })),
                ...times(3, () => span('view.render', { 'view.name': 'orders.total' })),
                ...times(3, () => span('HTTP GET', { 'http.request.method': 'GET', 'server.address': 'api.stripe.com' }, { kind: 'client' })),
                ...times(3, () => span('HTTP GET', { 'http.request.method': 'GET', 'server.address': 'api.github.com' }, { kind: 'client' })),
                ...times(3, () => span('HTTP GET', { 'http.request.method': 'POST', 'server.address': 'api.github.com' }, { kind: 'client' })),
                ...times(3, () => span('redis', { 'db.system.name': 'redis', 'db.operation.name': 'GET' })),
                ...times(3, () => span('redis', { 'db.system.name': 'redis', 'db.operation.name': 'SET' })),
            ],
        });

        const groups = groupsOf(condense(rows, 3));
        expect(groups).toHaveLength(7);
        expect(groups.map((g) => g.label)).toEqual(['orders.row', 'orders.total', 'api.stripe.com', 'api.github.com', 'api.github.com', null, null]);
        // The current emitter puts the view in the name; the label does not repeat it.
        const named = groupsOf(condense(rowsOf({ name: 'root', children: times(3, () => span('view orders.row', { 'view.name': 'orders.row' })) }), 3));
        expect(named[0]!.label).toBeNull();
    });

    it('never groups outgoing calls by method alone, and finds the host in url.full', () => {
        const call = (attrs: Record<string, string>) => span('HTTP GET', { 'http.request.method': 'GET', ...attrs }, { kind: 'client' });
        const rows = rowsOf({
            name: 'root',
            children: [
                ...times(3, () => call({ 'url.full': 'https://api.stripe.com/v1/charges' })),
                ...times(3, () => call({ 'url.full': 'https://api.github.com/repos' })),
                // No host anywhere: the method says nothing, so it groups by name.
                ...times(3, () => call({})),
            ],
        });

        const groups = groupsOf(condense(rows, 3));
        expect(groups.map((g) => g.rows.length)).toEqual([3, 3, 3]);
        expect(groups.map((g) => g.label)).toEqual(['api.stripe.com', 'api.github.com', null]);
        expect(groupKey(rows[1]!.span)).not.toBe(groupKey(rows[4]!.span));
    });

    it('never groups orphans that surface at the top level', () => {
        // Tail sampling dropped their parent: unrelated entry points, not repeats.
        const orphans = [0, 1, 2].flatMap(() => rowsOf({ name: 'GET /health' }));
        expect(condense(orphans, 3).every((i) => i.kind === 'span')).toBe(true);
    });

    it('groups only consecutive siblings at or over the threshold', () => {
        const a = () => span('work.a');
        const rows = rowsOf({ name: 'root', children: [a(), a(), span('work.b'), a(), a(), a()] });

        expect(condense(rows, 3).map((i) => (i.kind === 'group' ? `group×${i.group.rows.length}` : i.row.span.name)))
            .toEqual(['root', 'work.a', 'work.a', 'work.b', 'group×3']);
        expect(groupsOf(condense(rows, 2)).map((g) => g.rows.length)).toEqual([2, 3]);
        // Under 2 is off: every span is its own row.
        expect(condense(rows, 0)).toHaveLength(rows.length);
        expect(condense(rows, 1)).toHaveLength(rows.length);
    });

    it('never merges spans under different parents, nor folds a span that has children', () => {
        const rows = rowsOf({
            name: 'root',
            children: [
                { name: 'job', children: [span('q'), span('q')] },
                { name: 'job', children: [span('q'), span('q')] },
                { name: 'job', children: [span('q')] },
            ],
        });

        // Six `q` across three parents (2 + 2 + 1), and three `job`s that each
        // have children: nothing qualifies, so nothing hides a subtree.
        expect(groupsOf(condense(rows, 2)).map((g) => g.rows.length)).toEqual([2, 2]);
        expect(groupsOf(condense(rows, 3))).toEqual([]);
    });

    it('counts the failed occurrences so a group never hides an error', () => {
        const rows = rowsOf({ name: 'root', children: times(10, (i) => span('q', {}, { error: i === 2 || i === 7 })) });
        const [group] = groupsOf(condense(rows, 3));

        expect(group!.errors).toEqual([2, 7]);
    });

    it('frames the mini-timeline by the parent, and buckets it', () => {
        const rows = rowsOf({ name: 'root', children: [span('pad'), { name: 'parent', children: times(100, () => span('q')) }] });
        const [group] = groupsOf(condense(rows, 3));
        const parent = rows.find((r) => r.span.name === 'parent')!;

        expect(group!.windowStartPct).toBe(parent.offsetPct);
        expect(group!.windowEndPct).toBe(parent.offsetPct + parent.widthPct);

        const buckets = timelineBuckets(group!.rows, group!.windowStartPct, group!.windowEndPct, 32);
        expect(buckets.length).toBeLessThanOrEqual(32);
        expect(buckets.reduce((n, b) => n + b.count, 0)).toBe(100);
        expect(buckets[0]!.at).toBe(0);
        expect(buckets.at(-1)!.at).toBe(31);
    });

    it('condenses a 5,000-span trace in a few milliseconds', () => {
        const rows = bigTrace();
        expect(rows).toHaveLength(5000);

        condense(rows, 3);
        const runs = 20;
        const started = performance.now();
        let items = condense(rows, 3);
        for (let i = 1; i < runs; i++) items = condense(rows, 3);
        const each = (performance.now() - started) / runs;

        // 1,200 queries, 1,500 cache reads and 40 × 30 job queries become groups.
        const groups = groupsOf(items);
        expect(groups.find((g) => g.rows.length === 1200)?.repeat).toBe('n_plus_one');
        expect(groups.find((g) => g.rows.length === 1200)?.errors).toEqual([700]);
        expect(groups.find((g) => g.rows.length === 1500)?.label).toBe('user:*');
        expect(groups.find((g) => g.rows.length === 1057)?.name).toBe('view partials.order-row');
        expect(items.length).toBeLessThan(100);

        // Measured ~1–3 ms on an M-series laptop; the bound is a regression
        // tripwire, not the target.
        console.info(`condense(5,000 spans): ${each.toFixed(2)} ms → ${items.length} rows`);
        expect(each).toBeLessThan(50);
    });
});
