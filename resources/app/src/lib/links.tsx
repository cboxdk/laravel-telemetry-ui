import { usePrefetch } from '../api/hooks';
import { useNavigation } from './navigation';
import { type MouseEvent, type ReactNode, useCallback } from 'react';
import type { Link as LinkData } from '../api/types';
import { formatDrawer, parseDrawer, pushDrawer, scopeOf, stringifySearch, parseSearch, type DrawerEntry, type Search } from './search';

/**
 * v1 detail pages became entity pages: a link to one resolves to the story
 * page for that entity instead (same param, now the entity value).
 */
const DETAIL_TO_ENTITY: Record<string, { type: string; param: string }> = {
    'request-detail': { type: 'route', param: 'route' },
    'job-detail': { type: 'job', param: 'job' },
    'queue-detail': { type: 'queue', param: 'queue' },
    'host-detail': { type: 'host', param: 'host' },
    'query-detail': { type: 'query', param: 'dbq' },
    'outgoing-detail': { type: 'outgoing', param: 'host' },
    'page-detail': { type: 'path', param: 'path' },
};

export interface Target {
    pathname: string;
    search: Search;
}

/**
 * Resolve a link to a location relative to the router base. Drawer links
 * (trace/error/issue) stay on the current page and push onto the stack.
 */
export function resolve(link: LinkData, current: { pathname: string; search: Search }, replaceDrawer = false): Target | { href: string } | null {
    const scope = scopeOf(current.search);
    const drawer = (entry: DrawerEntry): Target => ({
        pathname: current.pathname,
        search: { ...current.search, drawer: formatDrawer(pushDrawer(parseDrawer(typeof current.search.drawer === 'string' ? current.search.drawer : ''), entry, replaceDrawer)) },
    });

    switch (link.to) {
        case 'entity':
            return { pathname: `/entity/${encodeURIComponent(link.type)}`, search: { ...scope, value: link.value } };
        case 'trace':
            return drawer({ type: 'trace', id: link.id });
        case 'error':
            return drawer({ type: 'error', id: link.group });
        case 'issue':
            return drawer({ type: 'issue', id: link.id });
        case 'explore': {
            // A link's own window (from/to) replaces the preset period.
            const params = link.params ?? {};
            const windowed = params.from && params.to ? { period: undefined } : {};
            return { pathname: `/explore/${link.signal}`, search: { ...scope, ...windowed, ...params, where: link.where ?? [] } };
        }
        case 'entities':
            return { pathname: `/entities/${encodeURIComponent(link.type)}`, search: scope };
        case 'page': {
            const params = link.params ?? {};
            const entity = DETAIL_TO_ENTITY[link.page];
            if (entity && params[entity.param]) {
                return { pathname: `/entity/${entity.type}`, search: { ...scope, value: params[entity.param] } };
            }
            if (link.page === 'error-detail' && params.group) {
                return { pathname: `/errors/${encodeURIComponent(params.group)}`, search: scope };
            }
            if (link.page === 'dashboard') return { pathname: '/', search: scope };
            return { pathname: `/p/${link.page}`, search: { ...scope, ...params } };
        }
        case 'url':
            return { href: link.href };
        case 'param':
            return null;
    }
}

export function useResolve() {
    const navigation = useNavigation();
    return useCallback(
        (link: LinkData, replaceDrawer = false) => resolve(link, { pathname: navigation.pathname, search: parseSearch(navigation.searchStr) }, replaceDrawer),
        [navigation],
    );
}

/** Imperative navigation to a link (row clicks, keyboard). */
export function useGo() {
    const navigation = useNavigation();
    const resolveLink = useResolve();
    return useCallback(
        (link: LinkData, opts: { replaceDrawer?: boolean; newTab?: boolean; replace?: boolean } = {}) => {
            const target = resolveLink(link, opts.replaceDrawer);
            if (!target) return;
            if ('href' in target) {
                window.open(target.href, opts.newTab ? '_blank' : '_self', 'noopener');
                return;
            }
            if (opts.newTab) {
                window.open(navigation.href(target.pathname, target.search), '_blank', 'noopener');
                return;
            }
            navigation.go({ pathname: target.pathname, search: target.search, replace: opts.replace });
        },
        [navigation, resolveLink],
    );
}

export function hrefFor(base: string, target: Target): string {
    return `${base.replace(/\/$/, '')}${target.pathname}${stringifySearch(target.search)}`;
}

/**
 * An anchor for a link payload: a real href (middle-click / copy link work),
 * client-side navigation on a plain click.
 */
export function Go({ link, children, className, title, replaceDrawer, onParam }: {
    link: LinkData;
    children: ReactNode;
    className?: string;
    title?: string;
    replaceDrawer?: boolean;
    onParam?: (params: Record<string, string>) => void;
}) {
    const navigation = useNavigation();
    const resolveLink = useResolve();
    const go = useGo();
    const prefetch = usePrefetch();

    if (link.to === 'param') {
        return (
            <button type="button" className={className ? `t-linkbtn ${className}` : 't-linkbtn'} title={title} onClick={(e) => { e.stopPropagation(); onParam?.(link.params); }}>
                {children}
            </button>
        );
    }

    const target = resolveLink(link, replaceDrawer);
    const href = !target ? '#' : 'href' in target ? target.href : navigation.href(target.pathname, target.search);
    const external = link.to === 'url';

    const onClick = (e: MouseEvent) => {
        e.stopPropagation();
        if (external || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
        e.preventDefault();
        go(link, { replaceDrawer });
    };

    return (
        <a href={href} className={className} title={title} onClick={onClick} onMouseEnter={() => prefetch(link)} onFocus={() => prefetch(link)} {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}>
            {children}
        </a>
    );
}
