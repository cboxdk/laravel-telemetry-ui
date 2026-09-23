import { useMemo, useState, type ReactNode } from 'react';
import { createContext, useContext } from 'react';
import { useRouter, useRouterState } from '@tanstack/react-router';
import { parseSearch, stringifySearch, type Search } from './search';

/**
 * Where the view's state lives.
 *
 * The dashboard's rule is "the URL is the query": filters, the time window and
 * the drawer stack are all search params. Standalone, that URL is the browser's
 * and TanStack Router owns it. Embedded in a host app (its own router, its own
 * URL), the same components need somewhere else to put it — so everything goes
 * through this one interface instead of reaching for the router directly.
 */
export interface Navigation {
    pathname: string;
    /** The search string, `?a=b` style. */
    searchStr: string;
    /** Absolute href for a target, for real anchors (middle-click, copy link). */
    href(pathname: string, search: Search): string;
    go(target: { pathname?: string; search: Search; replace?: boolean }): void;
}

const NavigationContext = createContext<Navigation | null>(null);

export function useNavigation(): Navigation {
    const navigation = useContext(NavigationContext);

    if (navigation === null) {
        throw new Error('Telemetry UI components need a <TelemetryUiProvider> (or the dashboard router) above them.');
    }

    return navigation;
}

/** The standalone dashboard: the browser URL, through TanStack Router. */
export function RouterNavigation({ children }: { children: ReactNode }) {
    const router = useRouter();
    const location = useRouterState({ select: (s) => s.location });
    const base = router.options.basepath ?? '';

    const value = useMemo<Navigation>(() => ({
        pathname: location.pathname,
        searchStr: location.searchStr ?? '',
        href: (pathname, search) => `${base.replace(/\/$/, '')}${pathname}${stringifySearch(search)}`,
        go: ({ pathname, search, replace }) => void router.navigate({ to: pathname ?? '.', search: search as never, replace }),
    }), [router, location.pathname, location.searchStr, base]);

    return <NavigationContext.Provider value={value}>{children}</NavigationContext.Provider>;
}

/**
 * Embedded: the view's state lives in React, not in the host's URL — filtering
 * a panel inside someone else's page shouldn't rewrite their address bar. Pass
 * `search` + `onSearchChange` to bridge it to the host's own router when you do
 * want it in the URL.
 */
export function MemoryNavigation({ children, pathname = '/', search, onSearchChange }: {
    children: ReactNode;
    pathname?: string;
    search?: string;
    onSearchChange?: (search: string) => void;
}) {
    const [own, setOwn] = useState('');
    const [path, setPath] = useState(pathname);
    const controlled = search !== undefined;
    const searchStr = controlled ? search : own;

    const value = useMemo<Navigation>(() => ({
        pathname: path,
        searchStr,
        href: (to, next) => `${to}${stringifySearch(next)}`,
        go: ({ pathname: to, search: next }) => {
            const encoded = stringifySearch(next);
            if (to !== undefined && to !== '.') setPath(to);
            if (controlled) onSearchChange?.(encoded);
            else setOwn(encoded);
        },
    }), [path, searchStr, controlled, onSearchChange]);

    return <NavigationContext.Provider value={value}>{children}</NavigationContext.Provider>;
}

/** The current search params, parsed. */
export function useSearchParams(): Search {
    const { searchStr } = useNavigation();

    return useMemo(() => parseSearch(searchStr), [searchStr]);
}
