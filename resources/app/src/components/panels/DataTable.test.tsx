import { describe, expect, it } from 'vitest';
import { templateMinWidth } from './DataTable';

describe('table → card fallback', () => {
    it('sums px tracks, minmax() minimums, gaps and padding', () => {
        // 60 + 160 + 96 + 2 × 12 gap + 16 padding
        expect(templateMinWidth('60px minmax(160px, 4fr) 96px')).toBe(356);
    });

    it('counts unknown tracks conservatively', () => {
        expect(templateMinWidth('1fr')).toBe(56);
    });
});
