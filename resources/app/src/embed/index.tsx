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
import { PanelView } from '../components/panels/PanelView';
import { TraceView } from '../components/drawer/TraceView';
import { ErrorGroupView } from '../components/drawer/ErrorGroupView';
import { EntityIndexView, EntityView } from '../pages/EntityPages';
import { ExploreView } from '../pages/ExplorePage';
import { PanelPage } from '../pages/PanelPage';
import type { Signal } from '../api/types';

export { TelemetryUiProvider, type TelemetryUiConfig } from './TelemetryUiProvider';

/** One panel, by the id the API knows it by (`requests-activity`, `routes-table`, …). */
export function TelemetryPanel({ id, span = 2, params }: { id: string; span?: number; params?: Record<string, string> }) {
    return <div className="t-grid"><PanelView id={id} span={span} params={params} /></div>;
}

/** A whole registered page of panels (`requests`, `jobs`, a page you declared). */
export function TelemetryPage({ page, params }: { page: string; params?: Record<string, string> }) {
    return <PanelPage page={page} params={params} />;
}

/** The Explore surface over one signal. */
export function TelemetryExplore({ signal = 'requests' }: { signal?: Signal }) {
    return <ExploreView signal={signal} />;
}

/** One entity's story (the value comes from the view state's `value`). */
export function TelemetryEntity({ type }: { type: string }) {
    return <EntityView type={type} />;
}

/** Every value of an entity type, with RED. */
export function TelemetryEntities({ type }: { type: string }) {
    return <EntityIndexView type={type} />;
}

/** One trace, as the drawer renders it. */
export function TelemetryTrace({ traceId }: { traceId: string }) {
    return <TraceView traceId={traceId} full />;
}

/** One error group (issue). */
export function TelemetryIssue({ group }: { group: string }) {
    return <ErrorGroupView group={group} />;
}

export { useBoot, useDimension, DimensionValue } from '../components/DimensionValue';
export { usePanel, useExplore, useFacets, useEntityStory, useTrace, useErrorGroup } from '../api/hooks';
export { api, apiUrl } from '../api/client';
export type { Bootstrap, PanelPayload, ExploreResult, EntityStory, TraceData, ErrorGroupData, Signal, Link } from '../api/types';
