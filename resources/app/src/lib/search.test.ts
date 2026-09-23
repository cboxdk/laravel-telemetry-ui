import { describe, expect, it } from 'vitest';
import { formatDrawer, formatFilter, negate, parseDrawer, parseFilter, parseSearch, pushDrawer, scopeOf, stringifySearch, toggleFilter, withFilter } from './search';

describe('URL search (the URL is the query)', () => {
    it('round-trips PHP-style arrays and keeps explicit empty scope keys', () => {
        const search = parseSearch('?period=1h&service=&where[]=http.route%3D%2Fa&where[]=user.id!%3D4');
        expect(search).toEqual({ period: '1h', service: '', where: ['http.route=/a', 'user.id!=4'] });
        expect(parseSearch(stringifySearch(search))).toEqual(search);
        expect(stringifySearch({ service: '', q: '' })).toBe('?service=');
    });

    it('extracts only the scope for links to carry forward', () => {
        expect(scopeOf({ period: '24h', env: 'prod', where: ['a=b'], drawer: 'trace:x' })).toEqual({ period: '24h', env: 'prod' });
    });
});

describe('filters', () => {
    it.each([
        ['billing.customer_id=8655', 'billing.customer_id', '=', '8655'],
        ['http.response.status_code>=500', 'http.response.status_code', '>=', '500'],
        ['http.route!~/telemetry-ui.*', 'http.route', '!~', '/telemetry-ui.*'],
        ['url.full=https://x.test/a?b=c', 'url.full', '=', 'https://x.test/a?b=c'],
        ['db.query.text=select * from a where b != 1', 'db.query.text', '=', 'select * from a where b != 1'],
        ['user.id=', 'user.id', '=', ''],
    ])('parses %s', (raw, key, op, value) => {
        expect(parseFilter(raw)).toEqual({ key, op, value });
        expect(formatFilter(parseFilter(raw)!)).toBe(raw);
    });

    it('rejects input without an operator', () => {
        expect(parseFilter('just text')).toBeNull();
    });

    it('adds, replaces, inverts and toggles', () => {
        let where = withFilter([], { key: 'status', op: '=', value: 'error' });
        where = withFilter(where, { key: 'status', op: '!=', value: 'error' });
        expect(where).toEqual(['status!=error']);
        expect(negate('=~')).toBe('!~');
        where = toggleFilter(where, { key: 'user.id', op: '=', value: '4' });
        expect(where).toContain('user.id=4');
        expect(toggleFilter(where, { key: 'user.id', op: '=', value: '4' })).toEqual(['status!=error']);
    });
});

describe('drawer stack', () => {
    it('parses, pushes without duplicating the top, and replaces', () => {
        const stack = parseDrawer('error:0f3a9c2b1d4e~trace:abc');
        expect(stack).toEqual([{ type: 'error', id: '0f3a9c2b1d4e' }, { type: 'trace', id: 'abc' }]);
        expect(pushDrawer(stack, { type: 'trace', id: 'abc' })).toBe(stack);
        expect(formatDrawer(pushDrawer(stack, { type: 'issue', id: '12' }))).toBe('error:0f3a9c2b1d4e~trace:abc~issue:12');
        expect(pushDrawer(stack, { type: 'trace', id: 'z' }, true)).toEqual([{ type: 'trace', id: 'z' }]);
        expect(formatDrawer([])).toBeUndefined();
    });

    it('drops malformed entries', () => {
        expect(parseDrawer('trace:~bogus:1~issue:7')).toEqual([{ type: 'issue', id: '7' }]);
    });
});
