import { describe, expect, it } from 'vitest';
import { parseSse } from './liveTail';

describe('SSE parsing (polling fallback)', () => {
    it('collects rows batches and the last event id, ignoring keepalives', () => {
        const body = 'retry: 2000\n\n: keepalive\n\nid: 100\nevent: rows\ndata: {"rows":[{"m":1},{"m":2}]}\n\nid: 200\nevent: rows\ndata: {"rows":[{"m":3}]}\n\n';
        const parsed = parseSse<{ m: number }>(body);
        expect(parsed.rows.map((r) => r.m)).toEqual([1, 2, 3]);
        expect(parsed.lastId).toBe('200');
    });

    it('survives a malformed batch', () => {
        expect(parseSse('event: rows\ndata: {nope\n\n').rows).toEqual([]);
    });
});
