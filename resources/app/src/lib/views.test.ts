import { beforeEach, describe, expect, it } from 'vitest';
import { matchingView, removeView, savedViews, saveView } from './views';

beforeEach(() => localStorage.clear());

describe('saved views', () => {
    it('saves newest first and replaces a view with the same name', () => {
        saveView({ name: 'Checkout errors', pathname: '/explore/requests', search: '?where[]=status=error' });
        saveView({ name: 'Slow queries', pathname: '/explore/traces', search: '?where[]=duration>=1s' });
        saveView({ name: 'Checkout errors', pathname: '/explore/logs', search: '?q=checkout' });

        expect(savedViews().map((v) => [v.name, v.pathname])).toEqual([
            ['Checkout errors', '/explore/logs'],
            ['Slow queries', '/explore/traces'],
        ]);
    });

    it('finds the view matching the current page and query, and removes by name', () => {
        saveView({ name: 'Slow queries', pathname: '/explore/traces', search: '?where[]=duration>=1s' });

        expect(matchingView('/explore/traces', '?where[]=duration>=1s')?.name).toBe('Slow queries');
        expect(matchingView('/explore/traces', '?where[]=duration>=2s')).toBeUndefined();
        expect(removeView('Slow queries')).toEqual([]);
    });

    it('ignores an empty name and junk in storage', () => {
        localStorage.setItem('tui:views', '{"not":"an array"}');
        expect(savedViews()).toEqual([]);
        saveView({ name: '   ', pathname: '/x', search: '' });
        expect(savedViews()).toEqual([]);
    });
});
