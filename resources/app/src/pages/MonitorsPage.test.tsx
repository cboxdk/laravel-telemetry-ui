import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { MonitorsPayload } from '../api/types';
import { bootFixture, renderAt } from '../test/render';
import { MonitorsPage } from './MonitorsPage';

afterEach(() => vi.unstubAllGlobals());

const now = Math.floor(Date.now() / 1000);
const payload: MonitorsPayload = {
    services: [{ service: 'shop', transport: 'http', environment: 'production' }],
    monitors: [
        { id: '01JDA', service: 'shop', match: 'route', key: null, value: 'checkout.*', profile: true, expiresAt: now + 14 * 60, reason: 'ticket 881', createdBy: 'Ada <ada@example.com>', describe: 'route checkout.*' },
        { id: '01JDB', service: 'shop', match: 'context', key: 'tenant.id', value: '7', profile: false, expiresAt: now + 3 * 3600, reason: null, createdBy: 'mcp', describe: 'tenant.id = 7' },
    ],
    errors: { billing: 'billing refused the signature — check the secret and the clock.' },
    now,
    canManage: true,
};

function stub() {
    const calls: { url: string; method: string }[] = [];
    vi.stubGlobal('fetch', vi.fn((input: string, init?: RequestInit) => {
        calls.push({ url: String(input), method: init?.method ?? 'GET' });
        const body = init?.method === 'DELETE' ? { removed: '01JDA' } : payload;
        return Promise.resolve(new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    }));
    return calls;
}

describe('the monitors page', () => {
    it('lists active monitors with what they match, a countdown, the reason and who placed them', async () => {
        stub();
        await renderAt(<MonitorsPage />, '/monitors', { ...bootFixture, monitors: { services: ['shop'], page: true } });

        const table = await screen.findByRole('table', { name: 'Active monitors' });
        const rows = within(table).getAllByRole('row');
        expect(rows).toHaveLength(3);
        expect(within(rows[1]!).getByText('checkout.*')).toBeInTheDocument();
        expect(within(rows[1]!).getByText('profile')).toBeInTheDocument();
        expect(within(rows[1]!).getByText('in 14 min')).toBeInTheDocument();
        expect(within(rows[1]!).getByText('ticket 881')).toBeInTheDocument();
        expect(within(rows[1]!).getByText('Ada <ada@example.com>')).toBeInTheDocument();
        expect(within(rows[2]!).getByText('tenant.id = 7')).toBeInTheDocument();
        expect(within(rows[2]!).getByText('in 3 h')).toBeInTheDocument();
        // A service that could not be read says so.
        expect(screen.getByText(/refused the signature/)).toBeInTheDocument();
        // Each links to the traces it recorded.
        expect(within(rows[1]!).getByRole('link', { name: 'Traces' }).getAttribute('href')).toContain(encodeURIComponent('telemetry.directive=01JDA'));
    });

    it('stops a monitor early', async () => {
        const calls = stub();
        await renderAt(<MonitorsPage />, '/monitors', { ...bootFixture, monitors: { services: ['shop'], page: true } });

        await userEvent.click(await screen.findByRole('button', { name: 'Stop monitoring route checkout.*' }));

        await vi.waitFor(() => expect(calls.some((c) => c.method === 'DELETE')).toBe(true));
        expect(calls.find((c) => c.method === 'DELETE')!.url).toContain('/monitors/shop/01JDA');
    });
});
