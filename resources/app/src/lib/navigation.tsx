import { createContext, useContext, useMemo, useState, type AnchorHTMLAttributes, type MouseEvent, type ReactNode } from 'react';
import { useRouter, useRouterState } from '@tanstack/react-router';
import { boot } from '../boot';
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
    /** The page is ours (standalone), so the document title is too. */
    ownsDocument: boolean;
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
        ownsDocument: true,
    }), [router, location.pathname, location.searchStr, base]);

    return <NavigationContext.Provider value={value}>{children}</NavigationContext.Provider>;
}

/**
 * Embedded: the view's state lives in React, not in the host's URL — filtering
 * a panel inside someone else's page shouldn't rewrite their address bar. Pass
 * `search` + `onSearchChange` to bridge it to the host's own router when you do
 * want it in the URL.
 *
 * Changing the search (filters, window, the drawer) happens in place. Moving to
 * another *page* can't: an embed renders what the host put there, not a
 * router. That leaves for the full dashboard — or goes to `onNavigate`, so a
 * host can route it to a page of its own that embeds the target.
 */
export function MemoryNavigation({ children, pathname = '/', search, onSearchChange, onNavigate }: {
    children: ReactNode;
    pathname?: string;
    search?: string;
    onSearchChange?: (search: string) => void;
    /** A link to another dashboard page. Default: open it in the full dashboard. */
    onNavigate?: (target: { pathname: string; search: string; url: string }) => void;
}) {
    const [own, setOwn] = useState('');
    const controlled = search !== undefined;
    const searchStr = controlled ? search : own;

    const value = useMemo<Navigation>(() => ({
        pathname,
        searchStr,
        // Real anchors point at the full dashboard: middle-click and "copy
        // link" land somewhere that renders every page.
        href: (to, next) => `${boot().base}${to}${stringifySearch(next)}`,
        go: ({ pathname: to, search: next }) => {
            const encoded = stringifySearch(next);

            if (to !== undefined && to !== '.' && to !== pathname) {
                const url = `${boot().base}${to}${encoded}`;
                if (onNavigate) onNavigate({ pathname: to, search: encoded, url });
                else window.location.assign(url);
                return;
            }

            if (controlled) onSearchChange?.(encoded);
            else setOwn(encoded);
        },
        ownsDocument: false,
    }), [pathname, searchStr, controlled, onSearchChange, onNavigate]);

    return <NavigationContext.Provider value={value}>{children}</NavigationContext.Provider>;
}

/**
 * Tell the components below which dashboard page they are showing, so a link
 * to that same page (a filter, a drawer) stays in place and only a link to a
 * different page leaves. `claim` keeps other pages in place too, when the
 * mounted component can show them itself (Explore switching signal).
 */
export function PathScope({ pathname, claim, children }: {
    pathname: string;
    claim?: (pathname: string) => boolean;
    children: ReactNode;
}) {
    const parent = useNavigation();

    const value = useMemo<Navigation>(() => ({
        ...parent,
        pathname,
        go: (target) => {
            const here = target.pathname === pathname || (target.pathname !== undefined && claim?.(target.pathname) === true);
            parent.go(here ? { ...target, pathname: undefined } : target);
        },
    }), [parent, pathname, claim]);

    return <NavigationContext.Provider value={value}>{children}</NavigationContext.Provider>;
}

/**
 * An anchor into the dashboard that works under either navigation: a real
 * href for middle-click, a client-side move on a plain click.
 */
export function NavLink({ to, search = {}, children, ...rest }: {
    to: string;
    search?: Search;
    children: ReactNode;
} & Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'>) {
    const navigation = useNavigation();

    const onClick = (e: MouseEvent<HTMLAnchorElement>) => {
        rest.onClick?.(e);
        if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
        e.preventDefault();
        navigation.go({ pathname: to, search });
    };

    return <a {...rest} href={navigation.href(to, search)} onClick={onClick}>{children}</a>;
}

/** Whether the dashboard owns the page (so may set its title). */
export function useOwnsDocument(): boolean {
    return useContext(NavigationContext)?.ownsDocument ?? true;
}

/** The current search params, parsed. */
export function useSearchParams(): Search {
    const { searchStr } = useNavigation();

    return useMemo(() => parseSearch(searchStr), [searchStr]);
}
