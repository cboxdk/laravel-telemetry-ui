import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { setBoot } from '../boot';
import { MemoryNavigation } from '../lib/navigation';
import { TRACE_PREFETCH_DELAY_MS, usePrefetch } from './hooks';

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('warming traces on hover', () => {
    it('fetches only the trace the pointer rests on, not every row it crosses', async () => {
        vi.useFakeTimers();
        setBoot({ base: '/t', api: '/t/api/v2', assets: '/t/build', csrf: '' });
        const urls: string[] = [];
        vi.stubGlobal('fetch', vi.fn(async (input: string) => {
            urls.push(String(input));
            return new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json' } });
        }));

        let prefetch: ReturnType<typeof usePrefetch> | null = null;
        const Probe = () => { prefetch = usePrefetch(); return null; };
        render(
            <QueryClientProvider client={new QueryClient()}>
                <MemoryNavigation search="">
                    <Probe />
                </MemoryNavigation>
            </QueryClientProvider>,
        );

        // A sweep down three rows, faster than the delay.
        act(() => {
            prefetch!({ to: 'trace', id: 'aaa' });
            prefetch!({ to: 'trace', id: 'bbb' });
            prefetch!({ to: 'trace', id: 'ccc' });
        });
        expect(urls).toEqual([]);

        await act(async () => { vi.advanceTimersByTime(TRACE_PREFETCH_DELAY_MS); });

        expect(urls).toHaveLength(1);
        expect(urls[0]).toContain('/t/api/v2/traces/ccc');
    });
});
