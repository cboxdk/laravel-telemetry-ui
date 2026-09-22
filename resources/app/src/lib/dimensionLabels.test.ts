import { afterEach, describe, expect, it, vi } from 'vitest';
import { loadLabel } from './dimensionLabels';

afterEach(() => vi.unstubAllGlobals());

describe('display-name lookups', () => {
    it('batches every id asked for in one tick into one request per dimension', async () => {
        const fetch = vi.fn(async () => new Response(JSON.stringify({ labels: { '17': 'Jane Doe' } }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        vi.stubGlobal('fetch', fetch);

        const [a, b, again] = await Promise.all([loadLabel('user.id', '17'), loadLabel('user.id', '20'), loadLabel('user.id', '17')]);

        expect([a, b, again]).toEqual(['Jane Doe', null, 'Jane Doe']);
        expect(fetch).toHaveBeenCalledTimes(1);
        const url = String((fetch.mock.calls[0] as unknown as [string])[0]);
        expect(url).toContain('dimensions/labels?key=user.id');
        expect(decodeURIComponent(url)).toContain('values[]=17&values[]=20');
    });

    it('fails open: a broken endpoint leaves ids as ids', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('nope', { status: 500 })));

        await expect(loadLabel('user.id', '1')).resolves.toBeNull();
    });
});
