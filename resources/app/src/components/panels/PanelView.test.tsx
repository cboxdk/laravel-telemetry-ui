import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderAt } from '../../test/render';
import { PanelView } from './PanelView';

afterEach(() => vi.unstubAllGlobals());

const respond = (status: number, body: unknown) => vi.fn().mockResolvedValue(new Response(JSON.stringify(body), { status }));

describe('PanelView', () => {
    it('fetches its own payload with the scope and renders it', async () => {
        const fetch = respond(200, { id: 'jobs-table', span: 2, kind: 'table', title: 'Jobs', columns: [{ key: 'job', label: 'Job' }], rows: [{ job: 'SendInvoice' }] });
        vi.stubGlobal('fetch', fetch);
        await renderAt(<PanelView id="jobs-table" params={{ _page: 'jobs' }} />, '/p/jobs?period=24h&service=shop');

        expect(await screen.findByText('SendInvoice')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Jobs' })).toBeInTheDocument();
        const url = String(fetch.mock.calls[0]![0]);
        expect(url).toContain('/panels/jobs-table?');
        expect(url).toContain('period=24h');
        expect(url).toContain('service=shop');
        expect(url).toContain('_page=jobs');
    });

    it('shows a typed backend error instead of a blank panel', async () => {
        vi.stubGlobal('fetch', respond(502, { error: { type: 'backend', message: 'Tempo returned 503' } }));
        await renderAt(<PanelView id="trace-search" />, '/p/traces');
        expect(await screen.findByText('Backend unavailable')).toBeInTheDocument();
        expect(screen.getByText('Tempo returned 503')).toBeInTheDocument();
    });

    it('renders panel controls bound to URL params', async () => {
        vi.stubGlobal('fetch', respond(200, {
            id: 'unified-errors', span: 2, kind: 'table', title: 'Issues', columns: [], rows: [],
            controls: [{ param: 'err_sort', label: 'Sort', type: 'select', value: 'count', options: [{ value: 'count', label: 'Most events' }, { value: 'last', label: 'Last seen' }] }],
        }));
        await renderAt(<PanelView id="unified-errors" />, '/p/exceptions?err_sort=last');
        expect(await screen.findByRole('button', { name: 'Sort' })).toHaveTextContent('Last seen');
    });
});
