import { afterEach, describe, expect, it, vi } from 'vitest';
import { setBoot } from '../boot';
import { api, ApiError, toQuery } from './client';

afterEach(() => vi.unstubAllGlobals());

describe('API client', () => {
    it('builds PHP-style array query strings and keeps explicit empty params', () => {
        expect(toQuery({ where: ['a=b', 'c!=d'], service: '', skip: undefined, limit: 5 })).toBe('?where%5B%5D=a%3Db&where%5B%5D=c%21%3Dd&service=&limit=5');
    });

    it('prefixes the mounted API base', async () => {
        setBoot({ api: '/ops/telemetry/api/v2' });
        const fetch = vi.fn().mockResolvedValue(new Response('{"ok":true}', { status: 200 }));
        vi.stubGlobal('fetch', fetch);
        await api.get('bootstrap', { period: '1h' });
        expect(fetch.mock.calls[0]![0]).toBe('/ops/telemetry/api/v2/bootstrap?period=1h');
    });

    it.each([
        [502, { error: { type: 'backend', message: 'Tempo is down' } }, 'backend'],
        [403, { error: { type: 'forbidden', message: 'No.' } }, 'forbidden'],
        [404, null, 'not_found'],
    ])('turns a %i into a typed error', async (status, body, type) => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(body ? JSON.stringify(body) : '', { status })));
        const error = await api.get('x').catch((e: unknown) => e);
        expect(error).toBeInstanceOf(ApiError);
        expect((error as ApiError).type).toBe(type);
    });

    it('reports network failures as offline, and sends CSRF on writes', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));
        expect(((await api.get('x').catch((e: unknown) => e)) as ApiError).type).toBe('network');

        setBoot({ csrf: 'tok' });
        const fetch = vi.fn().mockResolvedValue(new Response('{}', { status: 201 }));
        vi.stubGlobal('fetch', fetch);
        await api.post('issues', { title: 't' });
        expect((fetch.mock.calls[0]![1] as RequestInit).headers).toMatchObject({ 'X-CSRF-TOKEN': 'tok' });
    });
});
