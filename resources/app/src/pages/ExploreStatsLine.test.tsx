import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { renderAt } from '../test/render';
import { StatsLine } from './ExplorePage';

const end = 1_790_000_000_000;
const sample = { size: 12, truncated: false, exact: false };

describe('StatsLine', () => {
    it('says when the store searched only the newest part of the window', async () => {
        await renderAt(
            <StatsLine signal="requests" stats={{ count: 12 }} sample={{ ...sample, searchedFrom: end - 168 * 3_600_000 }} range={{ start: end - 30 * 86_400_000, end }} where={[]} onWhere={() => {}} />,
        );

        expect(await screen.findByText('newest 7d only')).toHaveClass('t-pill-warn');
    });

    it('says nothing when the whole window was searched', async () => {
        await renderAt(<StatsLine signal="requests" stats={{ count: 12 }} sample={{ ...sample, searchedFrom: null }} range={{ start: end - 86_400_000, end }} where={[]} onWhere={() => {}} />);

        await screen.findByText('12 results');
        expect(screen.queryByText(/only$/)).toBeNull();
    });
});
