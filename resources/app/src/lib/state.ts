import { useCallback, useMemo, useSyncExternalStore } from 'react';
import { useNavigation, useSearchParams } from './navigation';
import { scopeOf, type Scope, type Search } from './search';
import { api } from '../api/client';

/** The current view state, parsed with PHP-style arrays (see {@see Navigation}). */
export function useSearchState(): Search {
    return useSearchParams();
}

/**
 * The scope the API is queried with: the URL's, else the one the server
 * remembered for this viewer.
 *
 * Two rules the server shares (see ViewState):
 * - the range is ONE unit — a `period` in the URL wins outright, and a
 *   remembered `from`/`to` is never mixed into it key by key, or picking a
 *   preset would look like it did nothing;
 * - before the remembered state has arrived, send nothing rather than a
 *   default, or the server reads that default as "the URL asked for it" and
 *   the cookie can never win.
 */
export function useScope(): Scope & { period?: string; service: string; env: string } {
    const search = useSearchState();
    const seeded = useSyncExternalStore(subscribeRemembered, () => rememberedVersion);

    return useMemo(() => {
        const s = scopeOf(search);
        const remembered = rememberedState;
        const urlRange = s.period !== undefined || (s.from !== undefined && s.to !== undefined);
        const range: Scope = urlRange
            ? { ...(s.period !== undefined ? { period: s.period } : {}), ...(s.from && s.to ? { from: s.from, to: s.to } : {}) }
            : remembered.from && remembered.to
                ? { from: remembered.from, to: remembered.to }
                : remembered.period !== undefined
                    ? { period: remembered.period }
                    : seeded > 0 ? { period: '1h' } : {};

        return {
            ...range,
            service: s.service ?? remembered.service ?? '',
            env: s.env ?? remembered.env ?? '',
        };
    }, [search, seeded]);
}

/** Seeded from /bootstrap's `state` (ViewState: URL, else cookie). */
let rememberedState: Scope & { refresh?: number } = {};

/** Bumped when the remembered state lands, so useScope() recomputes. */
let rememberedVersion = 0;
const rememberedListeners = new Set<() => void>();

function subscribeRemembered(listener: () => void): () => void {
    rememberedListeners.add(listener);
    return () => rememberedListeners.delete(listener);
}

export function seedRemembered(state: Scope & { refresh?: number }): void {
    rememberedState = { ...state };
    rememberedVersion += 1;
    for (const listener of rememberedListeners) listener();
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
    const navigation = useNavigation();
    const current = useSearchParams();
    return useCallback(
        (patch: Record<string, string | string[] | undefined>, opts: { replace?: boolean } = {}) => {
            const next: Search = { ...current, ...patch };
            for (const k of Object.keys(next)) if (next[k] === undefined) delete next[k];
            navigation.go({ search: next, replace: opts.replace });

            const scopeKeys = ['period', 'from', 'to', 'service', 'env', 'refresh'];
            const scopePatch = Object.fromEntries(Object.entries(patch).filter(([k]) => scopeKeys.includes(k)));
            if (Object.keys(scopePatch).length > 0) {
                rememberedState = { ...rememberedState, ...(scopePatch as Scope) };
                api.post('view-state', Object.fromEntries(Object.entries(scopePatch).map(([k, v]) => [k, v ?? '']))).catch(() => undefined);
            }
        },
        [navigation, current],
    );
}
