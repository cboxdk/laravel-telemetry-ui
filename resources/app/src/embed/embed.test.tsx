import { QueryClient } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { bootFixture } from '../test/render';
import { TelemetryPanel, TelemetryUiProvider } from './index';

afterEach(() => vi.unstubAllGlobals());

/** The host's app: no dashboard router, no shell document. */
function mount(ui: React.ReactNode) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <TelemetryUiProvider config={{ base: '/observability', csrf: 'token' }} client={client}>
            {ui}
        </TelemetryUiProvider>,
    );
}

describe('embedding in a host app', () => {
    it('renders a panel from the host-configured mount path, with no router above it', async () => {
        const urls: string[] = [];
        vi.stubGlobal('fetch', vi.fn(async (input: string) => {
            urls.push(String(input));
            const body = String(input).includes('/bootstrap')
                ? bootFixture
                : { id: 'requests-activity', span: 2, kind: 'chart', title: 'Requests', series: [], stats: [{ label: 'Requests', value: '1.2K', tone: null }] };

            return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
        }));

        mount(<TelemetryPanel id="requests-activity" />);

        expect(await screen.findByText('1.2K')).toBeInTheDocument();
        expect(screen.getAllByText('Requests').length).toBeGreaterThan(0);
        // The host said where the package is mounted; nothing read the DOM.
        expect(urls[0]).toContain('/observability/api/v2/bootstrap');
        expect(urls.some((u) => u.includes('/observability/api/v2/panels/requests-activity'))).toBe(true);
    });
});
