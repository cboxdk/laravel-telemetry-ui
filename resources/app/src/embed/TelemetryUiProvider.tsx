import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useBootstrap } from '../api/hooks';
import { setBoot } from '../boot';
import { BootContext } from '../components/DimensionValue';
import { DrawerStack } from '../components/drawer/DrawerStack';
import { ErrorState, Spinner } from '../components/States';
import { setTokenRoot } from '../lib/colors';
import { MemoryNavigation } from '../lib/navigation';
import { seedRemembered } from '../lib/state';

export interface TelemetryUiConfig {
    /** Where the package is mounted, e.g. `/observability`. */
    base: string;
    /** The host's CSRF token — needed only for the writes (view state, issues). */
    csrf?: string;
    /** The API root; defaults to `{base}/api/v2`. */
    api?: string;
}

/**
 * Everything a mounted Telemetry UI component needs: where to call, a query
 * client, the bootstrap payload (dimensions, pages, scope) and somewhere to
 * keep the view state.
 *
 * The host app owns its own routing, so by default the view state (filters,
 * window, drawer) lives in React rather than in the address bar. Pass
 * `search` + `onSearchChange` to put it in the host's URL instead.
 */
export function TelemetryUiProvider({ config, children, client, search, onSearchChange, onNavigate, pathname, className }: {
    config: TelemetryUiConfig;
    children: ReactNode;
    /** Extra classes on the root, e.g. `dark` to force the dark theme. */
    className?: string;
    /** Share the host's QueryClient instead of making one. */
    client?: QueryClient;
    search?: string;
    onSearchChange?: (search: string) => void;
    /**
     * A link to a different dashboard page than the one mounted (a route's
     * entity page from a panel, say). Default: open it in the full dashboard.
     * Handle it to route to a page of your own that embeds the target.
     */
    onNavigate?: (target: { pathname: string; search: string; url: string }) => void;
    pathname?: string;
}) {
    const [own] = useState(() => new QueryClient({ defaultOptions: { queries: { retry: 1, refetchOnWindowFocus: false, staleTime: 10_000 } } }));

    // Charts read their colours from this root, so the host's theme class and
    // token overrides on it apply to canvas-drawn charts as well.
    const root = useRef<HTMLDivElement>(null);
    useLayoutEffect(() => {
        setTokenRoot(root.current);
        return () => setTokenRoot(null);
    }, [className]);

    // Before anything fetches: the API path and the token come from the host.
    useMemo(() => {
        const base = config.base.replace(/\/$/, '');
        setBoot({ base, api: config.api ?? `${base}/api/v2`, assets: `${base}/build`, csrf: config.csrf ?? '' });
    }, [config.base, config.api, config.csrf]);

    return (
        <QueryClientProvider client={client ?? own}>
            <MemoryNavigation search={search} onSearchChange={onSearchChange} onNavigate={onNavigate} pathname={pathname}>
                {/* The components' element rules are scoped to this root, so
                    mounting them never restyles the host's page. */}
                <div ref={root} className={`t-scope ${className ?? ''}`}>
                    <Bootstrapped>{children}</Bootstrapped>
                </div>
            </MemoryNavigation>
        </QueryClientProvider>
    );
}

/** The bootstrap payload every component reads (dimensions, pages, scope). */
function Bootstrapped({ children }: { children: ReactNode }) {
    const { data: boot, error, isLoading } = useBootstrap();

    useEffect(() => {
        if (boot) seedRemembered(boot.state);
    }, [boot]);

    if (isLoading && !boot) return <div className="t-pad"><Spinner label="Loading telemetry…" /></div>;
    if (!boot) return <div className="t-pad"><ErrorState error={error} /></div>;

    // Rows open traces and issues in the drawer, as in the dashboard.
    return (
        <BootContext.Provider value={boot}>
            {children}
            <DrawerStack boot={boot} />
        </BootContext.Provider>
    );
}
