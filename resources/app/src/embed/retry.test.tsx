import { useQuery } from '@tanstack/react-query';
import { render, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../api/client';
import { bootFixture } from '../test/render';
import { TelemetryUiProvider } from './index';

afterEach(() => vi.unstubAllGlobals());

/** Explore answers 502 with this backend status; returns how often Explore was asked. */
function exploreFailingWith(backend: string): () => number {
    let asked = 0;
    vi.stubGlobal('fetch', vi.fn(async (input: string) => {
        if (String(input).includes('/bootstrap')) {
            return new Response(JSON.stringify(bootFixture), { status: 200, headers: { 'Content-Type': 'application/json' } });
        }
        asked++;
        return new Response(JSON.stringify({ error: { type: 'backend', message: 'The telemetry backend did not answer within 10s.', backend } }), { status: 502 });
    }));

    return () => asked;
}

function Explore({ onDone }: { onDone: () => void }) {
    const query = useQuery({ queryKey: ['explore'], queryFn: () => api.get('explore/requests') });
    if (query.isError) onDone();
    return null;
}

/** The provider with its own query client, as a host that passes none gets it. */
async function readUntilFailed(): Promise<void> {
    let failed = false;
    render(
        <TelemetryUiProvider config={{ base: '/observability' }}>
            <Explore onDone={() => { failed = true; }} />
        </TelemetryUiProvider>,
    );
    await waitFor(() => expect(failed).toBe(true), { timeout: 4000 });
}

describe('retrying a failed read in an embed', () => {
    it('does not send a read that timed out again', async () => {
        const asked = exploreFailingWith('timeout');
        await readUntilFailed();
        expect(asked()).toBe(1);
    });

    it('sends a read again when the backend dropped the connection', async () => {
        const asked = exploreFailingWith('dropped');
        await readUntilFailed();
        expect(asked()).toBe(2);
    });
});
