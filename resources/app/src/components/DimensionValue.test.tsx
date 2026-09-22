import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { renderAt } from '../test/render';
import { DimensionValue } from './DimensionValue';

describe('DimensionValue drill-down', () => {
    it('filters Explore to the value', async () => {
        const { router } = await renderAt(<DimensionValue dimKey="hubhus.customer_id" value="8655" chip label />, '/traces/abc?period=24h');
        await userEvent.click(await screen.findByTitle('Customer = 8655'));
        await userEvent.click(screen.getByRole('button', { name: /Filter to this/ }));
        expect(router.state.location.pathname).toBe('/explore/requests');
        expect(router.state.location.searchStr).toContain('period=24h');
        expect(decodeURIComponent(router.state.location.searchStr)).toContain('where[]=hubhus.customer_id=8655');
    });

    it('opens the entity page and offers the host link out', async () => {
        const { router } = await renderAt(<DimensionValue dimKey="hubhus.customer_id" value="8655" linkOut="https://app.test/c/8655" />);
        await userEvent.click(await screen.findByTitle('Customer = 8655'));
        expect(screen.getByRole('link', { name: /Open in app/ })).toHaveAttribute('href', 'https://app.test/c/8655');
        await userEvent.click(screen.getByRole('button', { name: /Open Customer/ }));
        expect(router.state.location.pathname).toBe('/entity/hubhus.customer_id');
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
