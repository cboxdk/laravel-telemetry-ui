import { describe, expect, it } from 'vitest';
import { bootFixture } from '../test/render';
import { canMonitor, expiresIn, monitorTarget, traceTargets } from './monitors';

const dims = bootFixture.dimensions;

describe('what a value can be monitored as', () => {
    it('maps routes, paths, users and jobs to the directive matches the core knows', () => {
        expect(monitorTarget('http.route', 'checkout.show', dims)).toEqual({ match: 'route', key: null, value: 'checkout.show', label: 'Route checkout.show' });
        // The core matches paths without the leading slash.
        expect(monitorTarget('url.path', '/admin/users', dims)?.value).toBe('admin/users');
        expect(monitorTarget('user.id', '42', dims)?.match).toBe('user');
        expect(monitorTarget('laravel.job.class', 'App\\Jobs\\Sync', dims)?.match).toBe('job');
    });

    it('maps a declared dimension to a context directive on that key', () => {
        expect(monitorTarget('billing.customer_id', '8655', dims)).toEqual({ match: 'context', key: 'billing.customer_id', value: '8655', label: 'Customer = 8655' });
    });

    it('offers nothing a directive cannot match', () => {
        expect(monitorTarget('http.response.status_code', '500', dims)).toBeNull();
        expect(monitorTarget('client.address', '10.0.0.1', dims)).toBeNull();
        expect(monitorTarget('user.id', '(none)', dims)).toBeNull();
        expect(monitorTarget('url.path', '/', dims)).toBeNull();
    });

    it('collects a trace\'s user, route and declared dimensions', () => {
        type Carrier = { service: string; attributes: Record<string, string> };
        const root: Carrier = { service: 'edge', attributes: { 'user.id': '7', 'client.address': '1.2.3.4' } };
        const spans: Carrier[] = [root, { service: 'shop', attributes: { 'http.route': 'orders.index' } }, { service: 'billing', attributes: { 'billing.customer_id': '8655' } }];
        const targets = traceTargets(spans, root, dims);
        expect(targets.map((t) => t.label)).toEqual(['User 7', 'Route orders.index', 'Customer = 8655']);
        // Each goes to the service of the span that carried it: the route to
        // the app that served it, not the proxy at the root.
        expect(targets.map((t) => t.service)).toEqual(['edge', 'shop', 'billing']);
    });

    it('is only offered to a viewer who may manage, when a service can be monitored', () => {
        expect(canMonitor(bootFixture)).toBe(false);
        expect(canMonitor({ ...bootFixture, abilities: { manage: true, createIssues: false }, monitors: { services: ['shop'], page: true } })).toBe(true);
        expect(canMonitor({ ...bootFixture, abilities: { manage: true, createIssues: false }, monitors: { services: [], page: true } })).toBe(false);
        expect(canMonitor({ ...bootFixture, abilities: { manage: false, createIssues: false }, monitors: { services: ['shop'], page: true } })).toBe(false);
    });

    it('counts down in words', () => {
        expect(expiresIn(-1)).toBe('expired');
        expect(expiresIn(42)).toBe('in 42 s');
        expect(expiresIn(14 * 60 - 10)).toBe('in 14 min');
        expect(expiresIn(3 * 3600 + 5 * 60)).toBe('in 3 h 05 min');
        expect(expiresIn(24 * 3600)).toBe('in 24 h');
    });
});
