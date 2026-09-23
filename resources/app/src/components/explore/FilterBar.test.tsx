import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { bootFixture, renderAt } from '../../test/render';
import { FilterBar } from './FilterBar';

describe('FilterBar', () => {
    it('shows chips with dimension labels, marks custom and negated ones', async () => {
        await renderAt(<FilterBar where={['billing.customer_id=8655', 'http.response.status_code!=200']} onChange={() => {}} dimensions={bootFixture.dimensions} q="" onQ={() => {}} />);
        const chip = (await screen.findByText('Customer')).closest('.t-chip')!;
        expect(chip).toHaveClass('is-custom');
        expect(screen.getByTitle('http.response.status_code!=200')).toHaveClass('is-neg');
    });

    it('removes, inverts and adds filters', async () => {
        const onChange = vi.fn();
        await renderAt(<FilterBar where={['user.id=4']} onChange={onChange} dimensions={bootFixture.dimensions} q="" onQ={() => {}} />);

        await userEvent.click(await screen.findByRole('button', { name: 'Remove user.id=4' }));
        expect(onChange).toHaveBeenLastCalledWith([]);

        await userEvent.click(screen.getByTitle('Invert'));
        expect(onChange).toHaveBeenLastCalledWith(['user.id!=4']);

        await userEvent.click(screen.getByRole('button', { name: /filter/ }));
        await userEvent.type(screen.getByLabelText('Add filter'), 'http.route=/checkout{Enter}');
        expect(onChange).toHaveBeenLastCalledWith(['user.id=4', 'http.route=/checkout']);
    });

    it('submits free-text search', async () => {
        const onQ = vi.fn();
        await renderAt(<FilterBar where={[]} onChange={() => {}} dimensions={bootFixture.dimensions} q="" onQ={onQ} />);
        await userEvent.type(await screen.findByLabelText('Search'), 'checkout{Enter}');
        expect(onQ).toHaveBeenCalledWith('checkout');
    });
});
