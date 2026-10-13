import { act, fireEvent, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderAt } from '../../test/render';
import { bigTrace, rowsOf, span, times, traceOf } from '../../test/spans';
import { Waterfall } from './Waterfall';

const sql = 'select * from `orders` where `user_id` = ?';

/** A controller running an N+1 of 300 queries, two of which failed, then a view. */
function nPlusOne() {
    return traceOf(rowsOf({
        name: 'GET /orders',
        children: [
            ...times(300, (i) => span('db.query', {
                'db.system.name': 'mysql',
                'db.query.text': sql,
                'laravel.db.query.shape': 'f00d',
                'db.query.n': String(i + 1),
                ...(i >= 2 ? { 'laravel.db.query.repeat': 'n_plus_one' } : {}),
                ...(i === 2 ? { 'code.file.path': 'app/Http/Controllers/OrderController.php', 'code.line.number': '42' } : {}),
            }, { error: i === 120 || i === 250 })),
            span('view.render', { 'view.name': 'orders.index' }),
        ],
    }));
}

beforeEach(() => localStorage.clear());
afterEach(() => vi.restoreAllMocks());

/** The router mounts the route a tick after render: wait for the waterfall itself. */
async function mount(ui: ReactElement) {
    const utils = await renderAt(ui);
    await screen.findByRole('tree', { name: 'Spans' });
    return utils;
}

const pager = () => screen.getByRole('group', { name: /Occurrences of db\.query/ });

describe('the condensed waterfall', () => {
    it('folds an N+1 into one row with its count, totals, marker and origin by default', async () => {
        await mount(<Waterfall data={nPlusOne()} />);

        const group = screen.getByRole('treeitem', { name: 'db.query, 300 occurrences' });
        expect(within(group).getByText('×300')).toBeInTheDocument();
        expect(within(group).getByText('N+1')).toBeInTheDocument();
        expect(within(group).getByText('Controllers/OrderController.php:42')).toBeInTheDocument();
        expect(within(group).getByText(sql)).toBeInTheDocument();
        expect(within(group).getByText(/^Σ .* · max /)).toBeInTheDocument();
        // One row for the group, not three hundred.
        expect(screen.getAllByRole('treeitem')).toHaveLength(3);
        expect(screen.getByRole('button', { name: /Condensed/ })).toHaveAttribute('aria-pressed', 'true');
    });

    it('switches to the full waterfall and remembers it for this viewer', async () => {
        const { unmount } = await mount(<Waterfall data={nPlusOne()} />);

        await userEvent.click(screen.getByRole('button', { name: 'Full' }));
        expect(screen.queryByText('×300')).not.toBeInTheDocument();
        expect(localStorage.getItem('tui:waterfall.layout')).toBe('"full"');
        unmount();

        await mount(<Waterfall data={nPlusOne()} />);
        expect(screen.getByRole('button', { name: 'Full' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('still renders, condensed, when storage is unavailable', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('denied'); });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('denied'); });

        await mount(<Waterfall data={nPlusOne()} />);
        expect(screen.getByText('×300')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Full' }));
        expect(screen.queryByText('×300')).not.toBeInTheDocument();
    });

    it('pages through the occurrences one at a time, with buttons and arrow keys, into the span detail', async () => {
        await mount(<Waterfall data={nPlusOne()} />);

        await userEvent.click(screen.getByRole('treeitem', { name: 'db.query, 300 occurrences' }));
        expect(within(pager()).getByText('1 of 300')).toBeInTheDocument();
        expect(screen.getByText('Occurrence 1 of 300')).toBeInTheDocument();
        expect(pager()).toHaveFocus();

        await userEvent.click(screen.getByRole('button', { name: 'Next occurrence' }));
        expect(within(pager()).getByText('2 of 300')).toBeInTheDocument();
        // The detail panel is the selected occurrence's own attributes.
        expect(screen.getByText('Occurrence 2 of 300')).toBeInTheDocument();
        expect(screen.getByText('db.query.n').closest('.t-kv-row')).toHaveTextContent('2');

        fireEvent.keyDown(pager(), { key: 'ArrowRight' });
        expect(within(pager()).getByText('3 of 300')).toBeInTheDocument();
        // The crossing occurrence carries the line that ran it.
        expect(screen.getByText(/from app\/Http\/Controllers\/OrderController\.php:42/, { selector: 'p' })).toBeInTheDocument();

        fireEvent.keyDown(pager(), { key: 'End' });
        expect(within(pager()).getByText('300 of 300')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Next occurrence' })).toBeDisabled();

        fireEvent.keyDown(pager(), { key: 'Home' });
        fireEvent.keyDown(pager(), { key: 'ArrowLeft' });
        expect(within(pager()).getByText('1 of 300')).toBeInTheDocument();
    });

    it('counts failed occurrences on the row and goes straight to them', async () => {
        await mount(<Waterfall data={nPlusOne()} />);

        const group = screen.getByRole('treeitem', { name: 'db.query, 300 occurrences' });
        expect(group).toHaveClass('has-error');

        await userEvent.click(within(group).getByRole('button', { name: '2 failed' }));
        expect(within(pager()).getByText('121 of 300')).toBeInTheDocument();
        expect(screen.getByText('db.query.n').closest('.t-kv-row')).toHaveTextContent('121');

        await userEvent.click(screen.getByRole('button', { name: 'Next failure' }));
        expect(within(pager()).getByText('251 of 300')).toBeInTheDocument();

        // And round again: `e` is the key for it.
        fireEvent.keyDown(pager(), { key: 'e' });
        expect(within(pager()).getByText('121 of 300')).toBeInTheDocument();
    });

    it('shows every occurrence inline, and selects the one clicked', async () => {
        await mount(<Waterfall data={nPlusOne()} />);

        await userEvent.click(screen.getByRole('treeitem', { name: 'db.query, 300 occurrences' }));
        await userEvent.click(screen.getByRole('button', { name: 'Show all 300' }));

        // 300 occurrences is past the virtualisation threshold: jsdom has no
        // layout, so the list renders its first screenful rather than all.
        const occurrences = screen.getAllByText(/^#\d+$/);
        expect(occurrences.length).toBeGreaterThan(20);
        expect(occurrences.length).toBeLessThan(300);

        await userEvent.click(screen.getByText('#5'));
        expect(within(pager()).getByText('5 of 300')).toBeInTheDocument();
        expect(screen.getByText('Occurrence 5 of 300')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'One at a time' }));
        expect(screen.getAllByText(/^#\d+$/)).toHaveLength(1);
    });

    it('closes the group with Escape and drops its selection', async () => {
        await mount(<Waterfall data={nPlusOne()} />);

        await userEvent.click(screen.getByRole('treeitem', { name: 'db.query, 300 occurrences' }));
        fireEvent.keyDown(pager(), { key: 'Escape' });

        expect(screen.queryByRole('group', { name: /Occurrences of/ })).not.toBeInTheDocument();
        expect(screen.queryByText(/Occurrence \d+ of 300/)).not.toBeInTheDocument();
    });

    it('takes its threshold from the host, and groups nothing when it is off', async () => {
        const data = traceOf(rowsOf({ name: 'root', children: times(4, () => span('work')) }));

        await mount(<Waterfall data={data} threshold={5} />);
        expect(screen.queryByText('×4')).not.toBeInTheDocument();
        expect(screen.getAllByRole('treeitem')).toHaveLength(5);
    });
});

describe('rendering a 5,000-span trace', () => {
    it('mounts a few dozen rows, not thousands, condensed and full', async () => {
        const data = traceOf(bigTrace(), 2000);

        const t0 = performance.now();
        const { unmount } = await mount(<Waterfall data={data} />);
        const condensed = performance.now() - t0;
        const rows = screen.getAllByRole('treeitem').length;
        unmount();

        localStorage.setItem('tui:waterfall.layout', '"full"');
        const t1 = performance.now();
        await mount(<Waterfall data={data} />);
        const full = performance.now() - t1;
        const fullRows = screen.getAllByRole('treeitem').length;

        // jsdom renders without layout, so this measures React's work (the
        // condense pass, the row model, the mounted rows), not paint — see
        // docs/core-concepts/trace-waterfall.md for the browser numbers.
        console.info(`5,000 spans in jsdom: condensed ${condensed.toFixed(0)} ms (${rows} rows mounted), full ${full.toFixed(0)} ms (${fullRows} rows mounted)`);

        // Condensed, the whole trace is a few dozen rows; full, the list is
        // virtualised, so neither mounts thousands of rows.
        expect(rows).toBeLessThan(100);
        expect(fullRows).toBeLessThanOrEqual(150);
        // A tripwire for a regression to rendering every span (that took
        // ~180 ms in Chromium alone), not a target.
        expect(condensed).toBeLessThan(1000);
        expect(full).toBeLessThan(1000);
        await act(async () => {});
    });
});
