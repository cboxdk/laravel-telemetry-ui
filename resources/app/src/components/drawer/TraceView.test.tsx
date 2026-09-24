import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { TraceStory } from '../../api/types';
import { renderAt } from '../../test/render';
import { TraceView } from './TraceView';

afterEach(() => vi.unstubAllGlobals());

const span = {
    spanId: 'a1', parentSpanId: null, name: 'GET /orders', service: 'shop', kind: 'server',
    startNano: '1735689600000000000', startMs: 1735689600000, durationMs: 1200, error: false,
    browser: false, summary: null, attributes: {}, links: [],
};

const story: TraceStory = {
    traceId: 'abc123abc123abc123abc123abc123ab',
    root: span,
    durationMs: 1200,
    error: false,
    spanCount: 1,
    services: { shop: { 'service.name': 'shop' } },
    waterfall: [{ span, depth: 0, offsetPct: 0, widthPct: 100, ancestors: [], children: 0 }],
    chain: [],
    identities: {},
    report: { request: {}, requestHeaders: {}, responseHeaders: {}, totals: [], db: { items: [], duplicates: {} }, cache: { items: [], summary: {} }, redis: [], outgoing: [], queued: [], views: [], storage: [] },
    dimensionLinks: {},
} as unknown as TraceStory;

describe('the trace drawer', () => {
    it('draws the trace as soon as the trace store answers, before the metrics and logs around it', async () => {
        const urls: string[] = [];
        vi.stubGlobal('fetch', vi.fn((input: string) => {
            urls.push(String(input));

            // The context half never answers here: the drawer must not wait for it.
            if (String(input).includes('/context')) return new Promise<Response>(() => {});

            return Promise.resolve(new Response(JSON.stringify(story), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        }));

        await renderAt(<TraceView traceId={story.traceId} />);

        expect(await screen.findByRole('heading', { name: 'GET /orders' })).toBeInTheDocument();
        expect(screen.getByText('Reading the metrics and logs around this request…')).toBeInTheDocument();
        expect(urls[0]).toContain(`/traces/${story.traceId}?without=context`);
        expect(urls.some((u) => u.includes(`/traces/${story.traceId}/context`))).toBe(true);
    });
});
