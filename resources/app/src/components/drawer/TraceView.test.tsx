import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
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
    it('breaks an outgoing call into its transfer phases, sized against the phases and not the span', async () => {
        // Guzzle follows redirects itself: the phases describe the LAST hop
        // while the span covers them all. Normalising the widths against the
        // span would shrink every segment on a redirected call and read as a
        // fast request.
        const client = {
            ...span, spanId: 'b2', name: 'GET api.stripe.com', kind: 'client', durationMs: 400,
            attributes: {
                'server.address': 'api.stripe.com',
                'http.client.connection_reused': 'false',
                'http.client.dns_ms': '10',
                'http.client.tcp_ms': '10',
                'http.client.tls_ms': '30',
                'http.client.ttfb_ms': '100',
                'http.client.transfer_ms': '50',
            },
        };
        const withClient = {
            ...story, spanCount: 2,
            waterfall: [
                { span, depth: 0, offsetPct: 0, widthPct: 100, ancestors: [], children: 1 },
                { span: client, depth: 1, offsetPct: 10, widthPct: 30, ancestors: ['a1'], children: 0 },
            ],
        } as unknown as TraceStory;

        vi.stubGlobal('fetch', vi.fn((input: string) => String(input).includes('/context')
            ? new Promise<Response>(() => {})
            : Promise.resolve(new Response(JSON.stringify(withClient), { status: 200, headers: { 'Content-Type': 'application/json' } }))));

        await renderAt(<TraceView traceId={story.traceId} />);

        await userEvent.click(await screen.findByRole('tab', { name: /Waterfall/ }));
        await userEvent.click(screen.getByTitle('GET api.stripe.com'));

        expect(screen.getByText('Transfer phases')).toBeInTheDocument();
        expect(screen.getByText('DNS')).toBeInTheDocument();
        expect(screen.getByText('Waiting')).toBeInTheDocument();

        // 100 of 200ms of phases, not 100 of the span's 400ms.
        expect(screen.getByTitle(/^Waiting —/)).toHaveStyle({ width: '50%' });
    });

    it('says a connection was reused rather than reporting a 0ms lookup', async () => {
        const reused = {
            ...span, spanId: 'c3', name: 'GET api.stripe.com', kind: 'client', durationMs: 60,
            attributes: {
                'http.client.connection_reused': 'true',
                'http.client.ttfb_ms': '50',
                'http.client.transfer_ms': '10',
            },
        };
        const withReused = {
            ...story, spanCount: 2,
            waterfall: [
                { span, depth: 0, offsetPct: 0, widthPct: 100, ancestors: [], children: 1 },
                { span: reused, depth: 1, offsetPct: 10, widthPct: 30, ancestors: ['a1'], children: 0 },
            ],
        } as unknown as TraceStory;

        vi.stubGlobal('fetch', vi.fn((input: string) => String(input).includes('/context')
            ? new Promise<Response>(() => {})
            : Promise.resolve(new Response(JSON.stringify(withReused), { status: 200, headers: { 'Content-Type': 'application/json' } }))));

        await renderAt(<TraceView traceId={story.traceId} />);

        await userEvent.click(await screen.findByRole('tab', { name: /Waterfall/ }));
        await userEvent.click(screen.getByTitle('GET api.stripe.com'));

        expect(screen.getByText('Connection reused — no lookup or handshake was needed.')).toBeInTheDocument();
        expect(screen.queryByText('DNS')).not.toBeInTheDocument();
    });
});
