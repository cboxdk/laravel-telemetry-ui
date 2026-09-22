import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createMemoryHistory, createRootRoute, createRoute, createRouter, Outlet, RouterProvider } from '@tanstack/react-router';
import { render } from '@testing-library/react';
import type { ReactNode } from 'react';
import type { Bootstrap } from '../api/types';
import { BootContext } from '../components/DimensionValue';
import { parseSearch, stringifySearch } from '../lib/search';

export const bootFixture: Bootstrap = {
    app: { name: 'Telemetry', logo: null, accent: null, copyLink: true, version: '2.0' },
    nav: [{ group: 'Overview', pages: [{ slug: 'dashboard', label: 'Dashboard' }] }, { group: 'Activity', pages: [{ slug: 'jobs', label: 'Jobs' }] }],
    pages: { dashboard: { label: 'Dashboard', group: null, hidden: false }, jobs: { label: 'Jobs', group: 'Activity', hidden: false } },
    explore: [{ signal: 'requests', label: 'Requests' }, { signal: 'logs', label: 'Logs' }],
    entities: [
        { type: 'route', key: 'http.route', label: 'Route', plural: 'Routes', custom: false, group: 'Request' },
        { type: 'hubhus.customer_id', key: 'hubhus.customer_id', label: 'Customer', plural: 'Customers', custom: true, group: 'Hubhus' },
    ],
    dimensions: [
        { key: 'http.route', label: 'Route', group: 'Request', entity: 'route', scope: 'span', builtin: true, signals: ['requests'], format: null, plural: 'Routes', linksOut: false },
        { key: 'user.id', label: 'User', group: 'Identity', entity: 'user', scope: 'span', builtin: true, signals: ['requests'], format: null, plural: 'Users', linksOut: false },
        { key: 'hubhus.customer_id', label: 'Customer', group: 'Hubhus', entity: 'hubhus.customer_id', scope: 'span', builtin: false, signals: ['requests', 'traces'], format: null, plural: 'Customers', linksOut: true },
    ],
    navLinks: [],
    connections: [],
    currentConnection: '',
    scope: { services: ['shop'], environments: ['prod'], servicesLocked: false, environmentsLocked: false, error: null },
    state: { period: '1h', from: '', to: '', refresh: 0, service: '', env: '' },
    periods: [{ value: '15m', label: '15M' }, { value: '1h', label: '1H' }],
    refreshIntervals: [0, 10],
    abilities: { manage: false, createIssues: false },
    capabilities: { issues: false, exactAggregation: false },
    user: null,
};

/**
 * Render inside a real (memory) router + query client + boot context, at a
 * given URL, so links, search state and drill-downs behave as in the app.
 */
export async function renderAt(ui: ReactNode, url = '/explore/requests?period=1h') {
    const root = createRootRoute({ component: () => <Outlet /> });
    const page = createRoute({ getParentRoute: () => root, path: '$', component: () => <>{ui}</> });
    const home = createRoute({ getParentRoute: () => root, path: '/', component: () => <>{ui}</> });
    const router = createRouter({
        routeTree: root.addChildren([home, page]),
        history: createMemoryHistory({ initialEntries: [url] }),
        parseSearch: (s) => parseSearch(s),
        stringifySearch: (s) => stringifySearch(s),
    });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    const utils = render(
        <QueryClientProvider client={client}>
            <BootContext.Provider value={bootFixture}>
                <RouterProvider router={router} />
            </BootContext.Provider>
        </QueryClientProvider>,
    );

    return { ...utils, router };
}
