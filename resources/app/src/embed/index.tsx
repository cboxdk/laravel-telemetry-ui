/**
 * The embedding surface: mount pieces of the dashboard inside a host app's own
 * React tree (Inertia, a plain SPA, anything) instead of linking to the
 * standalone dashboard.
 *
 *     import { TelemetryUiProvider, TelemetryPanel } from '@cboxdk/telemetry-ui';
 *     import '@cboxdk/telemetry-ui/styles.css';
 *
 *     <TelemetryUiProvider config={{ base: '/observability', csrf }}>
 *         <TelemetryPanel id="requests-activity" />
 *     </TelemetryUiProvider>
 *
 * Every component here talks to the same gated, scope-locked API the
 * standalone dashboard uses, so embedding grants no access the host's own
 * `viewTelemetryUi` gate doesn't already allow.
 */
import { useCallback, useEffect, useState } from 'react';
import { PanelView } from '../components/panels/PanelView';
import { PathScope } from '../lib/navigation';
import { rememberTraceTime } from '../lib/traceTimes';
import { TraceView } from '../components/drawer/TraceView';
import { TopBar } from '../components/shell/TopBar';
import { useBoot } from '../components/DimensionValue';
import { ErrorGroupView } from '../components/drawer/ErrorGroupView';
import { EntityIndexView, EntityView } from '../pages/EntityPages';
import { ExploreView } from '../pages/ExplorePage';
import { PanelPage } from '../pages/PanelPage';
import type { Signal } from '../api/types';

export { TelemetryUiProvider, type TelemetryUiConfig } from './TelemetryUiProvider';

/**
 * The scope and time-window controls — service, environment, window, refresh,
 * copy link — for the top of a host's page. Every control writes the view
 * state, so the panels, Explore and entity pages mounted beside it follow.
 */
export function TelemetryToolbar() {
    const boot = useBoot();

    return <TopBar boot={boot} embedded />;
}

/** One panel, by the id the API knows it by (`requests-activity`, `routes-table`, …). */
export function TelemetryPanel({ id, span = 2, params }: { id: string; span?: number; params?: Record<string, string> }) {
    return <div className="t-grid"><PanelView id={id} span={span} params={params} /></div>;
}

/** A whole registered page of panels (`requests`, `jobs`, a page you declared). */
export function TelemetryPage({ page, params }: { page: string; params?: Record<string, string> }) {
    return <PathScope pathname={`/p/${page}`}><PanelPage page={page} params={params} /></PathScope>;
}

/**
 * The Explore surface, starting on one signal. Its signal tabs switch in
 * place (filters carry over) rather than leaving for the full dashboard.
 */
export function TelemetryExplore({ signal: initial = 'requests', onSignalChange }: {
    signal?: Signal;
    onSignalChange?: (signal: Signal) => void;
}) {
    const [signal, setSignal] = useState<Signal>(initial);
    useEffect(() => setSignal(initial), [initial]);

    const claim = useCallback((pathname: string) => {
        const match = /^\/explore\/(requests|traces|logs|errors)$/.exec(pathname);
        if (match === null) return false;
        setSignal(match[1] as Signal);
        onSignalChange?.(match[1] as Signal);
        return true;
    }, [onSignalChange]);

    return <PathScope pathname={`/explore/${signal}`} claim={claim}><ExploreView signal={signal} /></PathScope>;
}

/** One entity's story (the value comes from the view state's `value`). */
export function TelemetryEntity({ type }: { type: string }) {
    return <PathScope pathname={`/entity/${encodeURIComponent(type)}`}><EntityView type={type} /></PathScope>;
}

/** Every value of an entity type, with RED. */
export function TelemetryEntities({ type }: { type: string }) {
    return <PathScope pathname={`/entities/${encodeURIComponent(type)}`}><EntityIndexView type={type} /></PathScope>;
}

/**
 * One trace, as the drawer renders it. Pass `at` (epoch ms) when the host knows
 * when the request happened: the trace store is then asked about that stretch
 * of time only, which is several times faster than a lookup across retention.
 */
export function TelemetryTrace({ traceId, at }: { traceId: string; at?: number }) {
    rememberTraceTime(traceId, at);

    return <PathScope pathname={`/traces/${traceId}`}><TraceView traceId={traceId} full /></PathScope>;
}

/** One error group (issue). */
export function TelemetryIssue({ group }: { group: string }) {
    return <PathScope pathname={`/errors/${group}`}><ErrorGroupView group={group} /></PathScope>;
}

export { useBoot, useDimension, DimensionValue } from '../components/DimensionValue';
export { usePanel, useExplore, useFacets, useEntityStory, useTrace, useErrorGroup } from '../api/hooks';
export { api, apiUrl } from '../api/client';
export type { Bootstrap, PanelPayload, ExploreResult, EntityStory, TraceData, ErrorGroupData, Signal, Link } from '../api/types';
