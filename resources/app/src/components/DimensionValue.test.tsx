import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { renderAt } from '../test/render';
import { DimensionValue } from './DimensionValue';

describe('DimensionValue drill-down', () => {
    it('filters Explore to the value', async () => {
        const { router } = await renderAt(<DimensionValue dimKey="billing.customer_id" value="8655" chip label />, '/traces/abc?period=24h');
        await userEvent.click(await screen.findByTitle('Customer = 8655'));
        await userEvent.click(screen.getByRole('button', { name: /Filter to this/ }));
        expect(router.state.location.pathname).toBe('/explore/requests');
        expect(router.state.location.searchStr).toContain('period=24h');
        expect(decodeURIComponent(router.state.location.searchStr)).toContain('where[]=billing.customer_id=8655');
    });

    it('opens the entity page and offers the host link out', async () => {
        const { router } = await renderAt(<DimensionValue dimKey="billing.customer_id" value="8655" linkOut="https://app.test/c/8655" />);
        await userEvent.click(await screen.findByTitle('Customer = 8655'));
        expect(screen.getByRole('link', { name: /Open in app/ })).toHaveAttribute('href', 'https://app.test/c/8655');
        await userEvent.click(screen.getByRole('button', { name: /Open Customer/ }));
        expect(router.state.location.pathname).toBe('/entity/billing.customer_id');
        expect(router.state.location.searchStr).toContain('value=8655');
    });

    it('adds an exclusion when already on Explore', async () => {
        const { router } = await renderAt(<DimensionValue dimKey="user.id" value="4" />, '/explore/logs?where[]=level%3Derror');
        await userEvent.click(await screen.findByTitle('User = 4'));
        await userEvent.click(screen.getByRole('button', { name: /Exclude/ }));
        const search = decodeURIComponent(router.state.location.searchStr);
        expect(router.state.location.pathname).toBe('/explore/logs');
        expect(search).toContain('where[]=level=error');
        expect(search).toContain('where[]=user.id!=4');
    });
});

describe('DimensionValue with a cell link', () => {
    it('offers the panel filter first when the cell carries a param link', async () => {
        const onParam = vi.fn();
        await renderAt(<DimensionValue dimKey="client.address" value="10.0.0.1" link={{ to: 'param', params: { log_ip: '10.0.0.1' } }} onParam={onParam} />);
        await userEvent.click(await screen.findByTitle('client.address = 10.0.0.1'));
        await userEvent.click(screen.getByRole('button', { name: /Filter this panel/ }));
        expect(onParam).toHaveBeenCalledWith({ log_ip: '10.0.0.1' });
    });
});
