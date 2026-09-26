import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, afterEach } from 'vitest';
import { renderAt } from '../../test/render';
import { CellView } from './DataTable';

afterEach(() => vi.unstubAllGlobals());

function fetchOk() {
    const fetch = vi.fn(async (_url: RequestInfo | URL, _init?: RequestInit) =>
        new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetch);

    return fetch;
}

describe('row actions', () => {
    it('portals the menu out of the cell, which clips its content', async () => {
        await renderAt(<CellView cell={{ v: 'Open', actions: [{ label: 'Resolve', endpoint: 'insights/issues/a' }] }} />);

        await userEvent.click(await screen.findByRole('button', { name: 'Actions' }));

        // A table cell truncates with overflow:hidden, so a menu rendered
        // inside one is invisible in a real browser even though jsdom
        // happily reports it. It has to hang off the body.
        const menu = screen.getByRole('menu');

        expect(menu.parentElement).toBe(document.body);
        // Positioned against the trigger, not flowed inside the cell.
        // (jsdom loads no stylesheet, so the `fixed` itself is CSS's job.)
        expect(menu.style.top).not.toBe('');
        expect(menu.closest('.t-cell')).toBeNull();
    });

    it('posts the action to the dashboard API, with its body', async () => {
        const fetch = fetchOk();
        await renderAt(<CellView cell={{
            v: 'Open',
            actions: [{ label: 'Resolve', endpoint: 'insights/issues/aaaa0001', body: { action: 'resolve' } }],
        }} />);

        await userEvent.click(await screen.findByRole('button', { name: 'Actions' }));
        await userEvent.click(screen.getByRole('menuitem', { name: 'Resolve' }));

        await waitFor(() => expect(fetch).toHaveBeenCalled());

        const [url, init] = fetch.mock.calls[0]!;
        expect(String(url)).toContain('/api/v2/insights/issues/aaaa0001');
        expect(init?.method).toBe('POST');
        expect(JSON.parse(String(init?.body))).toEqual({ action: 'resolve' });
    });

    it('asks first when the action says to, and does nothing if you decline', async () => {
        const fetch = fetchOk();
        vi.stubGlobal('confirm', vi.fn(() => false));

        await renderAt(<CellView cell={{
            v: 'Open',
            actions: [{ label: 'Ignore', endpoint: 'insights/issues/a', confirm: 'Ignore this for good?' }],
        }} />);

        await userEvent.click(await screen.findByRole('button', { name: 'Actions' }));
        await userEvent.click(screen.getByRole('menuitem', { name: 'Ignore' }));

        expect(window.confirm).toHaveBeenCalledWith('Ignore this for good?');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('shows a refusal instead of pretending it worked', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response(
            JSON.stringify({ error: { type: 'forbidden', message: 'You are not authorized to change issues.' } }),
            { status: 403, headers: { 'Content-Type': 'application/json' } },
        )));

        await renderAt(<CellView cell={{ v: 'Open', actions: [{ label: 'Resolve', endpoint: 'insights/issues/a' }] }} />);

        await userEvent.click(await screen.findByRole('button', { name: 'Actions' }));
        await userEvent.click(screen.getByRole('menuitem', { name: 'Resolve' }));

        expect(await screen.findByText(/not authorized/i)).toBeInTheDocument();
    });

    it('stays out of the way on a cell with no actions', async () => {
        await renderAt(<CellView cell={{ v: '200' }} />);

        expect(screen.queryByRole('button', { name: 'Actions' })).toBeNull();
    });
});
