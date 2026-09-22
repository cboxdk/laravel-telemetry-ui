import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { PanelPayload } from '../../api/types';
import { renderAt } from '../../test/render';
import { PanelBody } from './PanelBody';

describe('panel renderers', () => {
    it('renders a table with badges, tones and a sortable raw column', async () => {
        const data: PanelPayload = {
            kind: 'table',
            title: 'Routes',
            columns: [{ key: 'route', label: 'Route' }, { key: 'method', label: 'Method' }, { key: 'total', label: 'Total', align: 'right' }],
            rows: [
                { route: { v: '/a', mono: true }, method: { v: 'GET', badge: 'GET', tone: 'info' }, total: { v: '2', raw: 2 } },
                { route: { v: '/b', mono: true }, method: { v: 'POST', badge: 'POST', tone: 'ok' }, total: { v: '10', raw: 10 } },
            ],
        };
        await renderAt(<PanelBody data={data} />);

        expect(await screen.findByText('/a')).toBeInTheDocument();
        expect(screen.getByText('POST')).toHaveClass('t-badge-ok');

        await userEvent.click(screen.getByRole('columnheader', { name: 'Total' }));
        const rows = screen.getAllByRole('row').slice(1);
        expect(within(rows[0]!).getByText('/b')).toBeInTheDocument();
    });

    it('whole-row drill-down opens the trace drawer', async () => {
        const data: PanelPayload = {
            kind: 'table',
            columns: [{ key: 'name', label: 'Trace' }],
            rows: [{ name: 'GET /checkout', _link: { to: 'trace', id: 'abc123' } }],
        };
        const { router } = await renderAt(<PanelBody data={data} />, '/p/traces?period=1h');
        await userEvent.click(await screen.findByText('GET /checkout'));
        expect(router.state.location.searchStr).toContain('drawer=trace%3Aabc123');
    });

    it('in-panel param links call back instead of navigating', async () => {
        const onParam = vi.fn();
        const data: PanelPayload = {
            kind: 'table',
            columns: [{ key: 'ip', label: 'IP' }],
            rows: [{ ip: { v: '10.0.0.1', link: { to: 'param', params: { log_ip: '10.0.0.1' } } } }],
        };
        await renderAt(<PanelBody data={data} onParam={onParam} />);
        await userEvent.click(await screen.findByText('10.0.0.1'));
        expect(onParam).toHaveBeenCalledWith({ log_ip: '10.0.0.1' });
    });

    it('renders stats, bars, kv, callouts and composites', async () => {
        const data: PanelPayload = {
            kind: 'composite',
            title: 'Mixed',
            parts: [
                { kind: 'stats', title: 'Now', items: [{ label: 'Requests', value: '1.2k' }, { label: 'P95', value: '420ms', tone: 'warn' }] },
                { kind: 'bars', title: 'Top pages', items: [{ label: '/pricing', value: 30, display: '30' }, { label: '/docs', value: 10 }] },
                { kind: 'kv', title: 'Facts', items: [{ label: 'First seen', value: '2d ago' }] },
                { kind: 'callout', title: 'Heads up', message: 'Sampled.', tone: 'warn' },
                { kind: 'hidden' },
            ],
        };
        await renderAt(<PanelBody data={data} />);
        expect(await screen.findByText('420ms')).toHaveClass('t-tone-warn');
        expect(screen.getByText('/pricing')).toBeInTheDocument();
        expect(screen.getByText('2d ago')).toBeInTheDocument();
        expect(screen.getByText('Sampled.')).toBeInTheDocument();
    });

    it('shows the payload error, the empty text, and says so for unknown kinds', async () => {
        await renderAt(
            <>
                <PanelBody data={{ kind: 'table', columns: [], rows: [], error: 'Prometheus returned 503' }} />
                <PanelBody data={{ kind: 'bars', items: [], empty: 'No analytics yet.' }} />
                <PanelBody data={{ kind: 'sparkles' } as unknown as PanelPayload} />
            </>,
        );
        expect(await screen.findByRole('alert')).toHaveTextContent('Prometheus returned 503');
        expect(screen.getByText('No analytics yet.')).toBeInTheDocument();
        expect(screen.getByText(/Unsupported panel kind/)).toBeInTheDocument();
    });

    it('highlights the throw line in code payloads', async () => {
        await renderAt(<PanelBody data={{ kind: 'code', text: 'a\n> b\nc', highlight: [2] }} />);
        expect(await screen.findByText('> b')).toHaveClass('is-hl');
    });
});
