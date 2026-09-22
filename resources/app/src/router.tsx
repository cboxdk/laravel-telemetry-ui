import { createRootRoute, createRoute, createRouter, Outlet } from '@tanstack/react-router';
import { AppShell } from './components/shell/AppShell';
import { EntityIndexPage, EntityPage } from './pages/EntityPages';
import { ExplorePage } from './pages/ExplorePage';
import { NotFound } from './pages/NotFound';
import { ErrorPage, OverviewPage, PanelPage } from './pages/PanelPage';
import { TracePage } from './pages/TracePage';
import { parseSearch, stringifySearch } from './lib/search';

const rootRoute = createRootRoute({ component: () => <Outlet />, notFoundComponent: NotFound });
const shell = createRoute({ getParentRoute: () => rootRoute, id: 'shell', component: AppShell });

const routes = [
    createRoute({ getParentRoute: () => shell, path: '/', component: OverviewPage }),
    createRoute({ getParentRoute: () => shell, path: '/explore/$signal', component: ExplorePage }),
    createRoute({ getParentRoute: () => shell, path: '/entities/$type', component: EntityIndexPage }),
    createRoute({ getParentRoute: () => shell, path: '/entity/$type', component: EntityPage }),
    createRoute({ getParentRoute: () => shell, path: '/p/$page', component: PanelPage }),
    createRoute({ getParentRoute: () => shell, path: '/errors/$group', component: ErrorPage }),
    createRoute({ getParentRoute: () => shell, path: '/traces/$traceId', component: TracePage }),
];

export function makeRouter(basepath: string) {
    return createRouter({
        routeTree: rootRoute.addChildren([shell.addChildren(routes)]),
        basepath: basepath === '' ? '/' : basepath,
        parseSearch: (s) => parseSearch(s),
        stringifySearch: (s) => stringifySearch(s),
        defaultPreload: false,
        scrollRestoration: true,
        defaultNotFoundComponent: NotFound,
    });
}
