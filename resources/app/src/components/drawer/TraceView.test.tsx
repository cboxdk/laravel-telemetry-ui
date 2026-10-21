import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { TraceStory } from '../../api/types';
import { bootFixture, renderAt } from '../../test/render';
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
    it('offers the trace as Markdown without fetching it until asked', async () => {
        const urls: string[] = [];
        vi.stubGlobal('fetch', vi.fn((input: string) => {
            urls.push(String(input));
            if (String(input).includes('/context')) return new Promise<Response>(() => {});
            return Promise.resolve(new Response(JSON.stringify(story), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        }));

        await renderAt(<TraceView traceId={story.traceId} />);

        expect(await screen.findByRole('button', { name: /Copy as Markdown/ })).toBeInTheDocument();
        expect(urls.some((u) => u.includes('/markdown'))).toBe(false);
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

    it('names an N+1 by its shape, with the line that ran it, instead of guessing from repeated text', async () => {
        const sql = 'select * from `users` where `users`.`id` = ? limit 1';
        const withRepeats = {
            ...story,
            report: {
                ...story.report,
                db: {
                    items: [{ name: 'mysql', detail: sql, durationMs: 2, spanId: 'q1', origin: 'app/Http/Controllers/OrderController.php:42' }],
                    duplicates: { [sql]: 12 },
                    repeats: [{ kind: 'n_plus_one', query: sql, count: 12, origin: 'app/Http/Controllers/OrderController.php:42' }],
                },
            },
        } as unknown as TraceStory;

        vi.stubGlobal('fetch', vi.fn((input: string) => String(input).includes('/context')
            ? new Promise<Response>(() => {})
            : Promise.resolve(new Response(JSON.stringify(withRepeats), { status: 200, headers: { 'Content-Type': 'application/json' } }))));

        await renderAt(<TraceView traceId={story.traceId} />);

        expect(await screen.findByText('N+1')).toBeInTheDocument();
        expect(screen.getByText(/· app\/Http\/Controllers\/OrderController\.php:42/)).toBeInTheDocument();
        // The row says where it ran, too.
        expect(screen.getByText('Controllers/OrderController.php:42')).toBeInTheDocument();
        // The emitter's markers replace the text-repeat guess.
        expect(screen.queryByText(/N\+1 suspect/)).not.toBeInTheDocument();
    });

    it('names an N+1 of outgoing calls in the Outgoing section, and on the span', async () => {
        const call = {
            ...span, spanId: 'h3', name: 'GET api.example.com', kind: 'client', durationMs: 20,
            attributes: {
                'http.request.method': 'GET',
                'server.address': 'api.example.com',
                'url.full': 'https://api.example.com/users/19',
                'url.template': '/users/{id}',
                'laravel.http.client.shape': 'cccc222222222222',
                'laravel.http.client.repeat': 'n_plus_one',
            },
        };
        const withCalls = {
            ...story, spanCount: 2,
            waterfall: [
                { span, depth: 0, offsetPct: 0, widthPct: 100, ancestors: [], children: 1 },
                { span: call, depth: 1, offsetPct: 10, widthPct: 5, ancestors: ['a1'], children: 0 },
            ],
            report: {
                ...story.report,
                outgoing: [{ name: '200', detail: 'https://api.example.com/users/19', durationMs: 20, spanId: 'h3' }],
                outgoingRepeats: [{ kind: 'n_plus_one', query: 'GET api.example.com/users/{id}', count: 5, origin: 'app/Services/Feed.php:17' }],
            },
        } as unknown as TraceStory;

        vi.stubGlobal('fetch', vi.fn((input: string) => String(input).includes('/context')
            ? new Promise<Response>(() => {})
            : Promise.resolve(new Response(JSON.stringify(withCalls), { status: 200, headers: { 'Content-Type': 'application/json' } }))));

        await renderAt(<TraceView traceId={story.traceId} />);

        expect(await screen.findByText('GET api.example.com/users/{id}')).toBeInTheDocument();
        expect(screen.getByText('N+1')).toBeInTheDocument();
        expect(screen.getByText(/· app\/Services\/Feed\.php:17/)).toBeInTheDocument();

        await userEvent.click(screen.getByRole('tab', { name: /Waterfall/ }));
        await userEvent.click(screen.getByTitle('GET api.example.com'));

        expect(screen.getByText(/Part of an N\+1: this endpoint/)).toBeInTheDocument();
    });

    it('shows the app line a query span ran from', async () => {
        const query = {
            ...span, spanId: 'q1', name: 'db.query', kind: 'client', durationMs: 4,
            attributes: {
                'db.query.text': 'select 1',
                'code.file.path': 'app/Models/Order.php',
                'code.line.number': '19',
                'code.function.name': 'App\\Models\\Order::lines',
                'laravel.db.query.repeat': 'n_plus_one',
            },
        };
        const withQuery = {
            ...story, spanCount: 2,
            waterfall: [
                { span, depth: 0, offsetPct: 0, widthPct: 100, ancestors: [], children: 1 },
                { span: query, depth: 1, offsetPct: 10, widthPct: 1, ancestors: ['a1'], children: 0 },
            ],
        } as unknown as TraceStory;

        vi.stubGlobal('fetch', vi.fn((input: string) => String(input).includes('/context')
            ? new Promise<Response>(() => {})
            : Promise.resolve(new Response(JSON.stringify(withQuery), { status: 200, headers: { 'Content-Type': 'application/json' } }))));

        await renderAt(<TraceView traceId={story.traceId} />);

        await userEvent.click(await screen.findByRole('tab', { name: /Waterfall/ }));
        await userEvent.click(screen.getByTitle('db.query'));

        expect(screen.getByText(/from app\/Models\/Order\.php:19/)).toBeInTheDocument();
        expect(screen.getByText(/Part of an N\+1/)).toBeInTheDocument();
    });

    describe('why it failed', () => {
        const job = {
            ...span, spanId: 'j1', parentSpanId: 'a1', name: 'App\\Jobs\\ChargeCard process', kind: 'consumer', durationMs: 30, error: true,
            attributes: {
                'exception.type': 'App\\Payments\\CardDeclined',
                'exception.message': 'Card declined',
                'exception.stacktrace': '#0 app/Payments/Charge.php(42)',
            },
        };
        const failed = {
            ...story, spanCount: 2, error: true,
            waterfall: [
                { span, depth: 0, offsetPct: 0, widthPct: 100, ancestors: [], children: 1 },
                { span: job, depth: 1, offsetPct: 10, widthPct: 3, ancestors: ['a1'], children: 0 },
            ],
        } as unknown as TraceStory;
        const answer = (context: unknown) => vi.fn((input: string) => Promise.resolve(new Response(
            JSON.stringify(String(input).includes('/context') ? context : failed),
            { status: 200, headers: { 'Content-Type': 'application/json' } },
        )));
        const quiet = { context: [], profile: [], logs: [], logsMatch: 'trace' };

        it('opens a failed span from the story, with the exception the span carries', async () => {
            vi.stubGlobal('fetch', answer({ ...quiet, exceptions: [] }));

            await renderAt(<TraceView traceId={story.traceId} />);
            await userEvent.click(await screen.findByRole('button', { name: 'Show span →' }));

            expect(screen.getByRole('tab', { name: /Waterfall/ })).toHaveAttribute('aria-selected', 'true');
            const detail = document.querySelector('.t-spandetail') as HTMLElement;
            expect(detail).toHaveTextContent('App\\Payments\\CardDeclined Card declined');
            expect(detail).toHaveTextContent('Stacktrace');
        });

        it('shows a recorded exception on the span that threw it, with its issue', async () => {
            vi.stubGlobal('fetch', answer({
                ...quiet,
                exceptions: [{ group: '0f3a9c2b1d4e', type: 'App\\Payments\\CardDeclined', message: 'Card declined', file: 'app/Payments/Charge.php', line: 42, source: 'backend', match: 'trace', spans: ['j1'], stacktrace: '#0 app/Payments/Charge.php(42)' }],
            }));

            await renderAt(<TraceView traceId={story.traceId} />);
            await userEvent.click(await screen.findByRole('button', { name: 'Show the span that threw it →' }));

            const detail = document.querySelector('.t-spandetail') as HTMLElement;
            expect(detail).toHaveTextContent('Payments/Charge.php:42');
            expect(detail.querySelector('a, button[data-go]') ?? detail).toHaveTextContent('Open issue');
        });

        it('says what a failed span gave as its reason when it carries no exception', async () => {
            const released = { ...job, attributes: { 'otel.status_description': 'released for retry' } };
            vi.stubGlobal('fetch', vi.fn((input: string) => Promise.resolve(new Response(
                JSON.stringify(String(input).includes('/context') ? { ...quiet, exceptions: [] } : { ...failed, waterfall: [failed.waterfall[0], { ...failed.waterfall[1], span: released }] }),
                { status: 200, headers: { 'Content-Type': 'application/json' } },
            ))));

            await renderAt(<TraceView traceId={story.traceId} />);
            await userEvent.click(await screen.findByRole('button', { name: 'Show span →' }));

            expect(document.querySelector('.t-spandetail')).toHaveTextContent('Failed: released for retry');
        });
    });

    it('offers to monitor the trace\'s user, route and declared dimensions, and says when a monitor kept it', async () => {
        const root = { ...span, attributes: { 'http.route': 'orders.index', 'user.id': '42', 'billing.customer_id': '8655', 'telemetry.directive': '01JDA' } };
        const monitored = { ...story, root, waterfall: [{ span: root, depth: 0, offsetPct: 0, widthPct: 100, ancestors: [], children: 0 }] } as unknown as TraceStory;
        vi.stubGlobal('fetch', vi.fn((input: string) => String(input).includes('/context')
            ? new Promise<Response>(() => {})
            : Promise.resolve(new Response(JSON.stringify(monitored), { status: 200, headers: { 'Content-Type': 'application/json' } }))));

        await renderAt(<TraceView traceId={story.traceId} />, '/explore/requests?period=1h', { ...bootFixture, abilities: { manage: true, createIssues: false }, monitors: { services: ['shop'], page: true } });

        expect((await screen.findByRole('link', { name: 'recorded by monitor' })).getAttribute('href')).toContain(encodeURIComponent('telemetry.directive=01JDA'));
        await userEvent.click(screen.getByRole('button', { name: /Monitor…/ }));
        expect(screen.getAllByRole('radio').map((r) => r.parentElement?.textContent)).toEqual(['User 42', 'Route orders.index', 'Customer = 8655']);
    });
});
