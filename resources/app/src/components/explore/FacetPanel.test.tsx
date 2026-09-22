import { fireEvent, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { FacetsResult } from '../../api/types';
import { renderAt } from '../../test/render';
import { FacetPanel } from './FacetPanel';

const data: FacetsResult = {
    signal: 'requests',
    exact: false,
    sample: 500,
    facets: [
        { key: 'http.response.status_code', label: 'Status code', group: 'Request', custom: false, values: [{ value: '200', count: 420 }, { value: '422', count: 37 }] },
        { key: 'hubhus.customer_id', label: 'Customer', group: 'Hubhus', custom: true, values: [{ value: '8655', count: 37 }] },
    ],
};

describe('FacetPanel', () => {
    it('leads with host-declared groups and labels the sample', async () => {
        await renderAt(<FacetPanel data={data} loading={false} where={[]} onWhere={() => {}} onGroupBy={() => {}} groupBy="" extraKeys={[]} onAddKey={() => {}} onRemoveKey={() => {}} />);
        const groups = await screen.findAllByText(/Hubhus|Request/, { selector: '.t-facet-group-title span' });
        expect(groups[0]).toHaveTextContent('Hubhus');
        expect(screen.getByText('sample · 500')).toBeInTheDocument();
    });

    it('click filters to a value, ⌥-click excludes it, and group-by toggles', async () => {
        const onWhere = vi.fn();
        const onGroupBy = vi.fn();
        await renderAt(<FacetPanel data={data} loading={false} where={[]} onWhere={onWhere} onGroupBy={onGroupBy} groupBy="" extraKeys={[]} onAddKey={() => {}} onRemoveKey={() => {}} />);

        await userEvent.click(await screen.findByTitle(/hubhus.customer_id = 8655/));
        expect(onWhere).toHaveBeenLastCalledWith(['hubhus.customer_id=8655']);

        fireEvent.click(screen.getByTitle(/http.response.status_code = 422/), { altKey: true });
        expect(onWhere).toHaveBeenLastCalledWith(['http.response.status_code!=422']);

        await userEvent.click(screen.getByTitle('Group by Customer'));
        expect(onGroupBy).toHaveBeenCalledWith('hubhus.customer_id');
    });

    it('marks active filters as selected', async () => {
        await renderAt(<FacetPanel data={data} loading={false} where={['hubhus.customer_id=8655']} onWhere={() => {}} onGroupBy={() => {}} groupBy="" extraKeys={[]} onAddKey={() => {}} onRemoveKey={() => {}} />);
        expect(await screen.findByTitle(/hubhus.customer_id = 8655/)).toHaveClass('is-sel');
    });
});
