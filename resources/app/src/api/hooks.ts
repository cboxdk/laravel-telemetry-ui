import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect } from 'react';
import { api, type Params } from './client';
import type {
    Bootstrap, EntityIndex, EntityStory, ErrorGroupData, ExploreResult, FacetsResult,
    IssueData, PageDef, PanelPayload, Signal, TraceData, Annotation,
} from './types';
import { useScope, useRefreshInterval } from '../lib/state';

// Scope is part of every query key, so changing the window/service refetches
// exactly what depends on it — and nothing round-trips for client-only state.

/**
 * Warm a link's data on hover: by the time the click lands, the trace, entity
 * story or page is usually already in the cache. Deduped and cheap — a hover
 * that goes nowhere costs one request that the click would have made anyway.
 */
export function usePrefetch() {
    const client = useQueryClient();
    const scope = useScope();

    return useCallback((link: { to: string } & Record<string, unknown>) => {
        const fetch = <T,>(queryKey: unknown[], path: string, params: Params = {}) =>
            void client.prefetchQuery({ queryKey, queryFn: ({ signal }) => api.get<T>(path, params, signal), staleTime: 30_000 });

        if (link.to === 'trace' && typeof link.id === 'string') {
            fetch<TraceData>(['trace', link.id], `traces/${link.id}`);
        } else if (link.to === 'error' && typeof link.group === 'string') {
            fetch<ErrorGroupData>(['error', link.group, scope.service, scope.env], `errors/${link.group}`, { service: scope.service, env: scope.env });
        } else if (link.to === 'entity' && typeof link.type === 'string' && typeof link.value === 'string') {
            const all: Params = { ...scope, value: link.value };
            fetch<EntityStory>(['entity', link.type, all], `entities/${encodeURIComponent(link.type)}/story`, all);
        } else if (link.to === 'entities' && typeof link.type === 'string') {
            fetch<EntityIndex>(['entities', link.type, { ...scope }], `entities/${encodeURIComponent(link.type)}`, { ...scope });
        } else if (link.to === 'page' && typeof link.page === 'string') {
            fetch<PageDef>(['page', link.page], `pages/${link.page}`);
        }
    }, [client, scope]);
}

/** `r` (and anything else firing `telemetry-ui:refresh`) refetches the page. */
export function useManualRefresh(): void {
    const client = useQueryClient();

    useEffect(() => {
        const onRefresh = () => void client.invalidateQueries();
        window.addEventListener('telemetry-ui:refresh', onRefresh);
        return () => window.removeEventListener('telemetry-ui:refresh', onRefresh);
    }, [client]);
}

/** Warm a page's panel manifest (the sidebar's own links). */
export function usePrefetchPage() {
    const client = useQueryClient();

    return useCallback((page: string) => {
        void client.prefetchQuery({ queryKey: ['page', page], queryFn: ({ signal }) => api.get<PageDef>(`pages/${page}`, {}, signal), staleTime: 5 * 60_000 });
    }, [client]);
}

export function useBootstrap() {
    const scope = useScope();
    return useQuery({
        queryKey: ['bootstrap', scope.service, scope.env],
        queryFn: ({ signal }) => api.get<Bootstrap>('bootstrap', scope, signal),
        staleTime: 60_000,
        placeholderData: keepPreviousData,
    });
}

export function usePage(page: string) {
    return useQuery({
        queryKey: ['page', page],
        queryFn: ({ signal }) => api.get<PageDef>(`pages/${page}`, {}, signal),
        staleTime: 5 * 60_000,
    });
}

export function usePanel(id: string, params: Record<string, string> = {}, live = false) {
    const scope = useScope();
    const refresh = useRefreshInterval();
    const all: Params = { ...scope, ...params };
    return useQuery({
        queryKey: ['panel', id, all],
        queryFn: ({ signal }) => api.get<PanelPayload & { id: string; span: number }>(`panels/${id}`, all, signal),
        placeholderData: keepPreviousData,
        refetchInterval: live ? 3000 : refresh > 0 ? refresh * 1000 : false,
    });
}

export function useExplore<R>(signal: Signal, params: Params) {
    const scope = useScope();
    const refresh = useRefreshInterval();
    const all: Params = { ...scope, ...params };
    return useQuery({
        queryKey: ['explore', signal, all],
        queryFn: ({ signal: abort }) => api.get<ExploreResult<R>>(`explore/${signal}`, all, abort),
        // Keep the old rows while a filter changes — never across signals:
        // request rows rendered as log lines (or vice versa) would crash.
        placeholderData: (previous, query) => (query?.queryKey[1] === signal ? previous : undefined),
        refetchInterval: refresh > 0 ? refresh * 1000 : false,
    });
}

export function useFacets(signal: Signal, params: Params) {
    const scope = useScope();
    const all: Params = { ...scope, ...params };
    return useQuery({
        queryKey: ['facets', signal, all],
        queryFn: ({ signal: abort }) => api.get<FacetsResult>(`facets/${signal}`, all, abort),
        placeholderData: keepPreviousData,
    });
}

export function useEntityIndex(type: string, params: Params = {}) {
    const scope = useScope();
    const all: Params = { ...scope, ...params };
    return useQuery({
        queryKey: ['entities', type, all],
        queryFn: ({ signal }) => api.get<EntityIndex>(`entities/${encodeURIComponent(type)}`, all, signal),
        placeholderData: keepPreviousData,
    });
}

export function useEntityStory(type: string, value: string, params: Params = {}) {
    const scope = useScope();
    const all: Params = { ...scope, ...params, value };
    return useQuery({
        queryKey: ['entity', type, all],
        queryFn: ({ signal }) => api.get<EntityStory>(`entities/${encodeURIComponent(type)}/story`, all, signal),
        placeholderData: keepPreviousData,
        enabled: value !== '',
    });
}

export function useTrace(traceId: string) {
    return useQuery({
        queryKey: ['trace', traceId],
        queryFn: ({ signal }) => api.get<TraceData>(`traces/${traceId}`, {}, signal),
        staleTime: 5 * 60_000,
        retry: 1,
    });
}

export function useErrorGroup(group: string) {
    const scope = useScope();
    return useQuery({
        queryKey: ['error', group, scope.service, scope.env],
        queryFn: ({ signal }) => api.get<ErrorGroupData>(`errors/${group}`, { service: scope.service, env: scope.env }, signal),
        staleTime: 30_000,
    });
}

export function useIssue(id: string) {
    return useQuery({
        queryKey: ['issue', id],
        queryFn: ({ signal }) => api.get<IssueData>(`issues/${encodeURIComponent(id)}`, {}, signal),
        staleTime: 60_000,
    });
}

export function useAnnotations() {
    const scope = useScope();
    return useQuery({
        queryKey: ['annotations', scope],
        queryFn: ({ signal }) => api.get<{ annotations: Annotation[] }>('annotations', scope, signal),
        staleTime: 30_000,
    });
}

export function useCreateIssue() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (draft: { title: string; body: string; labels: string[] }) => api.post<IssueData>('issues', draft),
        onSuccess: () => client.invalidateQueries({ queryKey: ['panel'] }),
    });
}
