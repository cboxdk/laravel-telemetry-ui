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

describe('theming an embed', () => {
    it('reads chart tokens from the provider root, so a host override reaches the charts', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(bootFixture), { status: 200, headers: { 'Content-Type': 'application/json' } })));
        const { tokenColor } = await import('../lib/colors');
        const spy = vi.spyOn(window, 'getComputedStyle');

        const { container } = mount(<span>chart</span>);
        tokenColor('primary');

        const root = container.querySelector('.t-scope');
        expect(root).not.toBeNull();
        expect(spy.mock.calls.some(([element]) => element === root)).toBe(true);
        spy.mockRestore();
    });
});

describe('navigating inside an embed', () => {
    it('keeps a change to the mounted page in place and hands a different page to the host', async () => {
        const { MemoryNavigation, PathScope, useNavigation } = await import('../lib/navigation');
        const { act } = await import('@testing-library/react');
        const onNavigate = vi.fn();
        const onSearchChange = vi.fn();
        let navigation: ReturnType<typeof useNavigation> | null = null;
        const Probe = () => { navigation = useNavigation(); return null; };

        render(
            <MemoryNavigation search="" onSearchChange={onSearchChange} onNavigate={onNavigate}>
                <PathScope pathname="/explore/requests"><Probe /></PathScope>
            </MemoryNavigation>,
        );

        act(() => navigation!.go({ pathname: '/explore/requests', search: { groupBy: 'http.route' } }));
        expect(onSearchChange).toHaveBeenCalledWith('?groupBy=http.route');
        expect(onNavigate).not.toHaveBeenCalled();

        act(() => navigation!.go({ pathname: '/entity/route', search: { value: '/checkout' } }));
        expect(onNavigate).toHaveBeenCalledWith(expect.objectContaining({ pathname: '/entity/route', search: '?value=%2Fcheckout' }));
    });
});

describe('an embedded Explore', () => {
    it('switches signal in place instead of leaving for the dashboard', async () => {
        const { MemoryNavigation, PathScope, useNavigation } = await import('../lib/navigation');
        const { act } = await import('@testing-library/react');
        const onNavigate = vi.fn();
        const onSearchChange = vi.fn();
        const claimed: string[] = [];
        let navigation: ReturnType<typeof useNavigation> | null = null;
        const Probe = () => { navigation = useNavigation(); return null; };
        const claim = (p: string) => { if (!p.startsWith('/explore/')) return false; claimed.push(p); return true; };

        render(
            <MemoryNavigation search="" onSearchChange={onSearchChange} onNavigate={onNavigate}>
                <PathScope pathname="/explore/requests" claim={claim}><Probe /></PathScope>
            </MemoryNavigation>,
        );

        act(() => navigation!.go({ pathname: '/explore/logs', search: { where: ['service.name=api'] } }));
        expect(claimed).toEqual(['/explore/logs']);
        expect(onSearchChange).toHaveBeenCalled();
        expect(onNavigate).not.toHaveBeenCalled();
    });
});
