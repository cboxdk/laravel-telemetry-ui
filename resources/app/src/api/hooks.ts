import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Params } from './client';
import type {
    Bootstrap, EntityIndex, EntityStory, ErrorGroupData, ExploreResult, FacetsResult,
    IssueData, PageDef, PanelPayload, Signal, TraceData, Annotation,
} from './types';
import { useScope, useRefreshInterval } from '../lib/state';

// Scope is part of every query key, so changing the window/service refetches
// exactly what depends on it — and nothing round-trips for client-only state.

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
