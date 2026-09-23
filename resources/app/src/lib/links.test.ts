import { describe, expect, it } from 'vitest';
import { resolve, type Target } from './links';

const current = { pathname: '/explore/requests', search: { period: '24h', service: 'shop', where: ['a=b'] } };

describe('link payloads → locations (the SPA owns routing)', () => {
    it('opens entities with the scope but not the view filters', () => {
        expect(resolve({ to: 'entity', type: 'billing.customer_id', value: '8655' }, current)).toEqual({
            pathname: '/entity/billing.customer_id',
            search: { period: '24h', service: 'shop', value: '8655' },
        });
    });

    it('pushes traces, errors and issues onto the drawer stack in place', () => {
        const first = resolve({ to: 'error', group: '0f3a9c2b1d4e' }, current) as Target;
        expect(first.pathname).toBe('/explore/requests');
        expect(first.search.drawer).toBe('error:0f3a9c2b1d4e');
        expect(first.search.where).toEqual(['a=b']);

        const second = resolve({ to: 'trace', id: 'abc' }, first) as Target;
        expect(second.search.drawer).toBe('error:0f3a9c2b1d4e~trace:abc');
        expect((resolve({ to: 'trace', id: 'z' }, first, true) as Target).search.drawer).toBe('trace:z');
    });

    it('maps v1 detail pages onto entity pages', () => {
        expect(resolve({ to: 'page', page: 'request-detail', params: { route: '/checkout' } }, current)).toEqual({
            pathname: '/entity/route',
            search: { period: '24h', service: 'shop', value: '/checkout' },
        });
        expect(resolve({ to: 'page', page: 'query-detail', params: { dbq: 'select 1' } }, current)).toMatchObject({ pathname: '/entity/query' });
        expect(resolve({ to: 'page', page: 'error-detail', params: { group: 'ab12' } }, current)).toMatchObject({ pathname: '/errors/ab12' });
        expect(resolve({ to: 'page', page: 'jobs' }, current)).toMatchObject({ pathname: '/p/jobs' });
        expect(resolve({ to: 'page', page: 'dashboard' }, current)).toMatchObject({ pathname: '/' });
    });

    it('opens explore with filters, passes external urls through, and leaves panel params to the panel', () => {
        expect(resolve({ to: 'explore', signal: 'logs', where: ['level=error'] }, current)).toEqual({
            pathname: '/explore/logs',
            search: { period: '24h', service: 'shop', where: ['level=error'] },
        });
        expect(resolve({ to: 'url', href: 'https://app.test/customers/1' }, current)).toEqual({ href: 'https://app.test/customers/1' });
        expect(resolve({ to: 'param', params: { log_ip: '1.2.3.4' } }, current)).toBeNull();
    });

    it('opens Explore with extra params, and a from/to window replaces the period', () => {
        expect(resolve({ to: 'explore', signal: 'logs', where: ['exception_group=abc'], params: { period: '30d', groupBy: 'user.id' } }, current)).toMatchObject({
            pathname: '/explore/logs',
            search: { period: '30d', groupBy: 'user.id', where: ['exception_group=abc'] },
        });
        const around = resolve({ to: 'explore', signal: 'logs', where: [], params: { from: '100', to: '200' } }, current) as Target;
        expect(around.search).toMatchObject({ from: '100', to: '200' });
        expect(around.search.period).toBeUndefined();
    });

    it('opens an entity index', () => {
        expect(resolve({ to: 'entities', type: 'query' }, current)).toMatchObject({ pathname: '/entities/query' });
    });
});
