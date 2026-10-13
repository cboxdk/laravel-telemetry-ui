import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Bootstrap } from '../api/types';
import { bootFixture, renderAt } from '../test/render';
import { DimensionValue } from './DimensionValue';

afterEach(() => vi.unstubAllGlobals());

const manager: Bootstrap = { ...bootFixture, abilities: { manage: true, createIssues: false }, monitors: { services: ['shop', 'billing'], page: true } };

function stubPost() {
    const calls: { url: string; method: string; body: unknown }[] = [];
    vi.stubGlobal('fetch', vi.fn((input: string, init?: RequestInit) => {
        calls.push({ url: String(input), method: init?.method ?? 'GET', body: init?.body ? JSON.parse(String(init.body)) : null });
        return Promise.resolve(new Response(JSON.stringify({ monitor: { id: '01JD', service: 'shop', match: 'context', key: 'billing.customer_id', value: '8655', profile: true, expiresAt: 0, reason: 'ticket 881', createdBy: 'Ada', describe: 'billing.customer_id = 8655' } }), { status: 201, headers: { 'Content-Type': 'application/json' } }));
    }));
    return calls;
}

describe('Monitor… from a value', () => {
    it('places a context monitor for a declared dimension, for the chosen time, with a profile', async () => {
        const calls = stubPost();
        await renderAt(<DimensionValue dimKey="billing.customer_id" value="8655" />, '/explore/requests?period=1h&service=shop', manager);

        await userEvent.click(await screen.findByTitle(/Customer = 8655/));
        await userEvent.click(screen.getByRole('button', { name: 'Monitor…' }));

        const dialog = screen.getByRole('dialog', { name: 'Monitor' });
        expect(within(dialog).getByText('Customer = 8655')).toBeInTheDocument();
        // The service in scope is preselected.
        expect(within(dialog).getByRole('combobox')).toHaveValue('shop');

        await userEvent.click(within(dialog).getByRole('button', { name: '4h' }));
        await userEvent.click(within(dialog).getByLabelText('With CPU profile'));
        await userEvent.type(within(dialog).getByRole('textbox'), 'ticket 881');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Start monitoring' }));

        expect(await within(dialog).findByText(/Every matching trace is kept, with a CPU profile/)).toBeInTheDocument();
        expect(calls).toHaveLength(1);
        expect(calls[0]!.method).toBe('POST');
        expect(calls[0]!.url).toContain('/monitors');
        expect(calls[0]!.body).toEqual({ service: 'shop', match: 'context', key: 'billing.customer_id', value: '8655', minutes: 240, profile: true, reason: 'ticket 881' });
    });

    it('shows the refusal the server gives', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response(JSON.stringify({ error: { type: 'forbidden', message: 'Your view is limited to tenant.id: a route monitor would record every tenant\'s traffic.' } }), { status: 403, headers: { 'Content-Type': 'application/json' } }))));
        await renderAt(<DimensionValue dimKey="http.route" value="checkout.show" />, '/explore/requests?period=1h', manager);

        await userEvent.click(await screen.findByTitle(/checkout\.show/));
        await userEvent.click(screen.getByRole('button', { name: 'Monitor…' }));
        await userEvent.click(screen.getByRole('button', { name: 'Start monitoring' }));

        expect(await screen.findByText(/every tenant's traffic/)).toBeInTheDocument();
    });

    it('is not offered without the manage ability, or for a value a directive cannot match', async () => {
        await renderAt(<><DimensionValue dimKey="user.id" value="42" /></>, '/explore/requests?period=1h', { ...manager, abilities: { manage: false, createIssues: false } });
        await userEvent.click(await screen.findByTitle(/User = 42/));
        expect(screen.queryByRole('button', { name: 'Monitor…' })).not.toBeInTheDocument();
    });

    it('is not offered for a status code', async () => {
        await renderAt(<DimensionValue dimKey="http.response.status_code" value="500" />, '/explore/requests?period=1h', manager);
        await userEvent.click(await screen.findByTitle(/= 500/));
        expect(screen.queryByRole('button', { name: 'Monitor…' })).not.toBeInTheDocument();
    });
});
