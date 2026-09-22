import { describe, expect, it } from 'vitest';
import { fillRows } from './PanelPage';

describe('two-column page grid', () => {
    it('widens a half-width panel that would sit alone in its row', () => {
        const spans = (xs: number[]) => fillRows(xs.map((span, i) => ({ id: String(i), span }))).map((p) => p.span);
        expect(spans([1, 1, 1, 2])).toEqual([1, 1, 2, 2]);
        expect(spans([2, 1])).toEqual([2, 2]);
        expect(spans([1, 1, 2, 1, 1])).toEqual([1, 1, 2, 1, 1]);
    });
});
