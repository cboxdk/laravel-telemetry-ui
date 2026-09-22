import { useCallback, useMemo } from 'react';
import { useNavigate, useRouterState } from '@tanstack/react-router';
import { parseSearch, scopeOf, type Scope, type Search } from './search';
import { api } from '../api/client';

/** The current URL search, parsed with PHP-style arrays. */
export function useSearchState(): Search {
    const search = useRouterState({ select: (s) => s.location.searchStr });
    return useMemo(() => parseSearch(search), [search]);
}

/** The scope the API is queried with: the URL's, else the remembered one. */
export function useScope(): Scope & { period: string; service: string; env: string } {
    const search = useSearchState();
    return useMemo(() => {
        const s = scopeOf(search);
        const remembered = rememberedState;
        return {
            period: s.period ?? remembered.period ?? '1h',
            ...(s.from || remembered.from ? { from: s.from ?? remembered.from } : {}),
            ...(s.to || remembered.to ? { to: s.to ?? remembered.to } : {}),
            service: s.service ?? remembered.service ?? '',
            env: s.env ?? remembered.env ?? '',
        };
    }, [search]);
}

/** Seeded from /bootstrap's `state` (ViewState: URL, else cookie). */
let rememberedState: Scope & { refresh?: number } = {};

export function seedRemembered(state: Scope & { refresh?: number }): void {
    rememberedState = { ...state };
}

export function useRefreshInterval(): number {
    const search = useSearchState();
    const v = Number(search.refresh ?? rememberedState.refresh ?? 0);
    return Number.isFinite(v) ? v : 0;
}

/**
 * Update search params in place (keeps the route), merging over the current
 * search. `undefined` removes a key. Scope changes are reported to the server
 * so the next visit starts from the same window.
 */
export function useSetSearch() {
    const navigate = useNavigate();
    return useCallback(
        (patch: Record<string, string | string[] | undefined>, opts: { replace?: boolean } = {}) => {
            void navigate({
                to: '.',
                search: ((prev: Record<string, unknown>) => {
                    const next: Record<string, unknown> = { ...prev, ...patch };
                    for (const k of Object.keys(next)) if (next[k] === undefined) delete next[k];
                    return next;
                }) as never,
                replace: opts.replace,
            });

            const scopeKeys = ['period', 'from', 'to', 'service', 'env', 'refresh'];
            const scopePatch = Object.fromEntries(Object.entries(patch).filter(([k]) => scopeKeys.includes(k)));
            if (Object.keys(scopePatch).length > 0) {
                rememberedState = { ...rememberedState, ...(scopePatch as Scope) };
                api.post('view-state', Object.fromEntries(Object.entries(scopePatch).map(([k, v]) => [k, v ?? '']))).catch(() => undefined);
            }
        },
        [navigate],
    );
}
