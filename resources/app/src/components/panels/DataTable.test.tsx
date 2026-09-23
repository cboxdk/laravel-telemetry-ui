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

describe('column tracks', () => {
    it('gives the free space to the widest text column when every track is fixed', async () => {
        const { columnTemplate } = await import('./DataTable');
        const tpl = columnTemplate(
            [{ key: 't', label: 'Time' }, { key: 'r', label: 'Request' }, { key: 'd', label: 'Duration', align: 'right' }],
            [{ t: { v: '12:00:01' }, r: { v: 'GET /orders/checkout' }, d: { v: '12ms' } }],
        );
        expect(tpl.split(' ').filter((t) => t.includes('fr'))).toHaveLength(1);
        expect(tpl).toMatch(/^\d+px minmax\(\d+px, 1fr\) \d+px$/);
    });
});

describe('card layout', () => {
    it('titles a card with the longest text column and puts short badges inline', async () => {
        const { cardLayout } = await import('./DataTable');
        const layout = cardLayout(
            [{ key: 'method', label: 'Method' }, { key: 'route', label: 'Route' }, { key: 'total', label: 'Total', align: 'right' }],
            [{ method: { v: 'GET', badge: 'GET' }, route: { v: '/orders/{id}/checkout' }, total: { v: '12' } }],
        );
        expect(layout.head?.key).toBe('route');
        expect(layout.badges.map((c) => c.key)).toEqual(['method']);
        expect(layout.rest.map((c) => c.key)).toEqual(['total']);
    });
});
