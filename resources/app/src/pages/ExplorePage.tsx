import { useParams } from '@tanstack/react-router';
import { useEffect, useMemo, useState } from 'react';
import { useAnnotations, useExplore, useFacets, usePrefetch } from '../api/hooks';
import type { ErrorRow, LogEntryRow, Signal, SpanRow } from '../api/types';
import { Combobox } from '../components/Combobox';
import { CopyButton } from '../components/CopyButton';
import { Popover } from '../components/Popover';
import { SavedViewsButton } from '../components/SavedViews';
import { useBoot } from '../components/DimensionValue';
import { ErrorState, Empty, Skeleton } from '../components/States';
import { FacetPanel } from '../components/explore/FacetPanel';
import { FilterBar } from '../components/explore/FilterBar';
import { LogList } from '../components/explore/LogList';
import { ErrorList, GroupTable, SpanList } from '../components/explore/Results';
import { HeatmapChart } from '../components/charts/HeatmapChart';
import { TimeChart } from '../components/charts/TimeChart';
import { Icon } from '../components/Icon';
import { apiUrl } from '../api/client';
import { count, ms, percent } from '../lib/format';
import { useGo } from '../lib/links';
import { NavLink } from '../lib/navigation';
import { useLiveTail } from '../lib/liveTail';
import { useTitle } from '../lib/title';
import { formatFilter, list, parseDrawer, parseFilter, scopeOf, str, toggleFilter, withFilter } from '../lib/search';
import { useScope, useSearchState, useSetSearch } from '../lib/state';

const TITLES: Record<Signal, string> = { requests: 'Requests', traces: 'Traces', logs: 'Logs', errors: 'Errors' };

/**
 * Explore — one surface over requests / traces / logs / errors: filter by any
 * dimension (facets + filter bar; the URL is the query), group by any key, a
 * distribution on top, a virtualised result list below. Clicking a row opens
 * the trace/issue story in the drawer.
 */
export function ExplorePage() {
    const { signal } = useParams({ strict: false }) as { signal: Signal };

    return <ExploreView signal={signal} />;
}

/** The surface itself, for a host that says which signal it wants. */
export function ExploreView({ signal }: { signal: Signal }) {
    const boot = useBoot();
    const search = useSearchState();
    const set = useSetSearch();
    const scope = useScope();
    const [live, setLive] = useState(false);
    useTitle(TITLES[signal], 'Explore');

    const where = list(search, 'where');
    const q = str(search, 'q');
    const groupBy = str(search, 'groupBy');
    const limit = str(search, 'limit');
    const annotations = useAnnotations().data?.annotations ?? [];
    const facetKeys = list(search, 'facets');
    const top = parseDrawer(str(search, 'drawer')).at(-1);

    const params = useMemo(() => ({ where, q, groupBy, ...(limit ? { limit } : {}) }), [where.join('|'), q, groupBy, limit]); // eslint-disable-line react-hooks/exhaustive-deps
    const explore = useExplore<SpanRow | LogEntryRow | ErrorRow>(signal, params);
    const facets = useFacets(signal, useMemo(() => ({ where, q, keys: facetKeys.length ? [...defaultKeys(boot, signal), ...facetKeys] : [], ...(limit ? { limit } : {}) }), [where.join('|'), q, facetKeys.join('|'), signal, limit])); // eslint-disable-line react-hooks/exhaustive-deps

    const data = explore.data;
    const streamable = signal === 'logs' || signal === 'requests';
    const newest = data?.rows[0] as (SpanRow & LogEntryRow) | undefined;
    const since = newest ? (newest.nano ?? String((newest.startMs ?? 0) * 1_000_000)) : null;
    const tail = useLiveTail<SpanRow | LogEntryRow>(signal === 'logs' ? 'logs' : 'requests', { ...scope, where, q }, live && streamable, since);

    const rows = useMemo(() => (data ? [...tail.rows, ...data.rows] : []), [data, tail.rows]);
    useDrawerStepping(top?.type === 'trace' ? top.id : null, rows as SpanRow[], signal);
    const groupOptions = boot.dimensions.filter((d) => d.scope !== 'intrinsic' && (signal !== 'errors')).map((d) => ({ value: d.key, label: d.label, hint: d.key }));

    return (
        <div className="t-explore-page">
            <div className="t-xbar">
                <div className="t-xbar-row">
                    <h1 className="t-page-title">{TITLES[signal]}</h1>
                    <div className="t-seg t-signal-tabs" role="tablist">
                        {boot.explore.map((s) => (
                            <NavLink key={s.signal} to={`/explore/${s.signal}`} search={{ ...scopeOf(search), where }} className={s.signal === signal ? 'is-on' : ''} role="tab" aria-selected={s.signal === signal}>
                                {s.label}
                            </NavLink>
                        ))}
                    </div>
                    <div className="t-topbar-spacer" />
                    <SavedViewsButton />
                    {streamable && (
                        <button type="button" className={`t-btn t-btn-sm ${live ? 't-btn-live' : 't-btn-secondary'}`} onClick={() => setLive((l) => !l)} title="Live tail over SSE">
                            <Icon name={live ? 'pause' : 'play'} size={12} />
                            {live ? `Live${tail.mode === 'poll' ? ' (polling)' : ''}` : 'Live tail'}
                        </button>
                    )}
                </div>
                <FilterBar
                    where={where}
                    onChange={(w) => set({ where: w })}
                    dimensions={boot.dimensions}
                    facetValues={(key) => facets.data?.facets.find((f) => f.key === key)?.values ?? []}
                    q={q}
                    onQ={(v) => set({ q: v || undefined })}
                    placeholder={signal === 'logs' ? 'Search log lines…' : signal === 'errors' ? 'Search exceptions…' : 'Search span names…'}
                />
            </div>

            <div className="t-explore">
                <FacetPanel
                    data={facets.data}
                    loading={facets.isLoading}
                    where={where}
                    onWhere={(w) => set({ where: w })}
                    groupBy={groupBy}
                    onGroupBy={(k) => set({ groupBy: k || undefined })}
                    extraKeys={facetKeys}
                    onAddKey={(k) => set({ facets: [...new Set([...facetKeys, k])] })}
                    onRemoveKey={(k) => set({ facets: facetKeys.filter((f) => f !== k) })}
                />

                <section className="t-results">
                    {explore.error && !data ? <div className="t-pad"><ErrorState error={explore.error} /></div> : !data ? <div className="t-pad"><Skeleton height={380} /></div> : (
                        <>
                            <div className="t-rtop">
                                <StatsLine signal={signal} stats={data.stats} sample={data.sample} where={where} onWhere={(w) => set({ where: w })} />
                                {data.query && <QueryPeek query={data.query} url={apiUrl(`explore/${signal}`, { ...scope, ...params })} />}
                                {signal !== 'errors' && (
                                    <div className="t-groupby">
                                        <span>Sample</span>
                                        <Combobox
                                            value={limit || String(data.sample.limit)}
                                            options={['200', '500', '1000', '2000'].map((v) => ({ value: v, label: `${v} newest` }))}
                                            onChange={(v) => set({ limit: v })}
                                            className="t-combo-sm"
                                        />
                                        <span>Group by</span>
                                        <Combobox value={groupBy} options={[{ value: '', label: 'None' }, ...groupOptions]} onChange={(v) => set({ groupBy: v || undefined })} className="t-combo-sm" mono />
                                    </div>
                                )}
                            </div>

                            <div className={`t-dist ${data.heatmap ? 'has-heat' : ''}`}>
                                <div className="t-dist-chart">
                                    <div className="t-cap">{signal === 'logs' ? 'Lines' : signal === 'errors' ? 'Events' : 'Throughput'} over time</div>
                                    <TimeChart
                                        type="stacked"
                                        height={130}
                                        annotations={annotations}
                                        min={data.range.start}
                                        max={data.range.end}
                                        series={[
                                            { name: signal === 'errors' ? 'events' : 'ok', data: data.series.count.map(([t, c], i) => [t, signal === 'errors' ? c : c - (data.series.errors[i]?.[1] ?? 0)]), color: 'var(--chart-1)' },
                                            ...(signal === 'errors' ? [] : [{ name: 'errors', data: data.series.errors, color: 'var(--chart-4)' }]),
                                        ]}
                                    />
                                </div>
                                {data.heatmap && data.heatmap.cells.length > 0 && (
                                    <div className="t-dist-heat">
                                        <div className="t-cap">Latency × time</div>
                                        <HeatmapChart
                                            xs={data.heatmap.xs}
                                            ys={data.heatmap.ys}
                                            cells={data.heatmap.cells}
                                            height={130}
                                            onCell={(x, y) => {
                                                const heat = data.heatmap!;
                                                const from = heat.xs[x];
                                                const band = heat.bands?.[y];
                                                if (from === undefined || !band || !heat.width) return;
                                                // The cell's window + latency band, as filters you can see and remove.
                                                const bare = where.filter((w) => parseFilter(w)?.key !== 'duration');
                                                const banded = [
                                                    ...bare,
                                                    ...(band[0] > 0 ? [formatFilter({ key: 'duration', op: '>=', value: `${band[0]}ms` })] : []),
                                                    ...(band[1] !== null ? [formatFilter({ key: 'duration', op: '<', value: `${band[1]}ms` })] : []),
                                                ];
                                                set({ where: banded, period: undefined, from: String(Math.floor(from / 1000)), to: String(Math.ceil((from + heat.width) / 1000)) });
                                            }}
                                        />
                                    </div>
                                )}
                            </div>

                            {data.groupBy && data.groups && data.groups.length > 0 && (
                                <GroupTable
                                    groupKey={data.groupBy}
                                    groups={data.groups}
                                    exact={data.sample.groupsExact}
                                    onFilter={(value, errorsOnly) => {
                                        const key = data.groupBy!;
                                        const scoped = value === '(none)' ? where : withFilter(where, { key, op: '=', value });
                                        set({ where: errorsOnly ? withFilter(scoped, { key: 'status', op: '=', value: 'error' }) : scoped, groupBy: undefined });
                                    }}
                                />
                            )}

                            {rows.length === 0 ? (
                                <NoMatches
                                    scope={scope}
                                    filters={where.length + (q ? 1 : 0)}
                                    onNow={() => set({ from: undefined, to: undefined })}
                                    onWiden={(period) => set({ period, from: undefined, to: undefined })}
                                    onClear={() => set({ where: undefined, q: undefined })}
                                />
                            ) : signal === 'logs' ? (
                                <LogList rows={rows as LogEntryRow[]} fresh={tail.rows.length} />
                            ) : signal === 'errors' ? (
                                <ErrorList rows={rows as unknown as ErrorRow[]} />
                            ) : (
                                <SpanList rows={rows as SpanRow[]} selected={top?.type === 'trace' ? top.id : undefined} fresh={tail.rows.length} />
                            )}
                        </>
                    )}
                </section>
            </div>
        </div>
    );
}

/**
 * With a trace open, j / k (and ↑ / ↓) walk the result list without closing
 * the drawer: triage a page of failures without touching the mouse.
 */
function useDrawerStepping(openTraceId: string | null, rows: SpanRow[], signal: Signal): void {
    const go = useGo();
    const prefetch = usePrefetch();

    // The next and previous trace are one keystroke away: have them ready.
    useEffect(() => {
        if (openTraceId === null) return;
        const at = rows.findIndex((r) => r.traceId === openTraceId);
        for (const neighbour of [rows[at + 1], rows[at - 1]]) {
            if (neighbour) prefetch({ to: 'trace', id: neighbour.traceId, at: neighbour.startMs });
        }
    }, [openTraceId, rows, prefetch]);

    useEffect(() => {
        if (openTraceId === null || signal === 'logs' || signal === 'errors') return;

        const onKey = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement | null;
            if (e.metaKey || e.ctrlKey || e.altKey) return;
            if (typeof target?.closest === 'function' && target.closest('input,textarea,select,[contenteditable]')) return;

            const step = e.key === 'j' || e.key === 'ArrowDown' ? 1 : e.key === 'k' || e.key === 'ArrowUp' ? -1 : 0;
            if (step === 0) return;

            const at = rows.findIndex((r) => r.traceId === openTraceId);
            const next = rows[(at === -1 ? 0 : at) + step];
            if (!next) return;

            e.preventDefault();
            go({ to: 'trace', id: next.traceId, at: next.startMs }, { replaceDrawer: true, replace: true });
        };

        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [openTraceId, rows, signal, go]);
}

/**
 * An empty result is a question, not a wall: offer the ways out that apply —
 * back to now when the window was stepped, a wider window, drop the filters.
 */
function NoMatches({ scope, filters, onNow, onWiden, onClear }: {
    scope: { period?: string; from?: string; to?: string };
    filters: number;
    onNow: () => void;
    onWiden: (period: string) => void;
    onClear: () => void;
}) {
    const stepped = Boolean(scope.from && scope.to);
    const wider = (scope.period !== undefined ? WIDER[scope.period] : undefined) ?? (stepped ? '24h' : null);

    return (
        <Empty>
            <p>Nothing matches{stepped || scope.period === undefined ? ' in this window' : ` in the last ${scope.period}`}{filters > 0 ? ` with ${filters} filter${filters === 1 ? '' : 's'}` : ''}.</p>
            <div className="t-empty-actions">
                {stepped && <button type="button" className="t-btn t-btn-sm t-btn-secondary" onClick={onNow}><Icon name="clock" size={12} />Back to now</button>}
                {wider && <button type="button" className="t-btn t-btn-sm t-btn-secondary" onClick={() => onWiden(wider)}>Widen to {wider}</button>}
                {filters > 0 && <button type="button" className="t-btn t-btn-sm t-btn-ghost" onClick={onClear}><Icon name="x" size={12} />Clear filters</button>}
            </div>
        </Empty>
    );
}

/**
 * "What did you actually ask the backend?" — the compiled TraceQL/LogQL for
 * this exact view, copyable, plus the API call behind it as curl. Filters in
 * the UI are a query you can take elsewhere.
 */
function QueryPeek({ query, url }: { query: { language: string; text: string }; url: string }) {
    const absolute = new URL(url, window.location.origin).toString();

    return (
        <Popover
            align="right"
            trigger={(toggle, open) => (
                <button type="button" className={`t-pill t-pill-btn ${open ? 'is-on' : ''}`} onClick={toggle} title={`Show the ${query.language} behind this view`}>
                    <Icon name="code" size={11} />{query.language}
                </button>
            )}
        >
            {() => (
                <div className="t-querypeek">
                    <div className="t-eyebrow">{query.language} sent to the backend</div>
                    <pre className="t-code">{query.text}</pre>
                    <div className="t-row-gap">
                        <CopyButton text={query.text} label={`Copy ${query.language}`} />
                        <CopyButton text={`curl -s '${absolute}' -H 'Accept: application/json'`} label="Copy as curl" />
                    </div>
                </div>
            )}
        </Popover>
    );
}

const WIDER: Record<string, string> = { '15m': '1h', '1h': '24h', '24h': '7d', '7d': '30d', '14d': '30d' };

function defaultKeys(boot: ReturnType<typeof useBoot>, signal: Signal): string[] {
    return boot.dimensions.filter((d) => !d.builtin || d.signals.includes(signal)).map((d) => d.key);
}

function StatsLine({ signal, stats, sample, where, onWhere }: {
    signal: Signal;
    stats: Record<string, unknown>;
    sample: { size: number; truncated: boolean; exact: boolean; readSideFiltered?: boolean };
    where: string[];
    onWhere: (where: string[]) => void;
}) {
    const n = (k: string) => (typeof stats[k] === 'number' ? (stats[k] as number) : null);
    const p95 = n('p95');
    // Every number that names a subset is a filter to that subset (click again to drop it).
    const items: { k: string; v: string; tone?: string; filter?: { key: string; op: '=' | '>='; value: string }; title?: string }[] =
        signal === 'errors'
            ? [
                { k: 'Events', v: count(n('count')) },
                { k: 'Issues', v: count(n('groups')) },
                { k: 'Users hit', v: count(n('users')) },
                { k: 'Frontend', v: count(n('frontend')), filter: { key: 'source', op: '=', value: 'frontend' } },
            ]
            : signal === 'logs'
              ? [
                  { k: 'Lines', v: count(n('count')) },
                  { k: 'Errors', v: count(n('errors')), tone: (n('errors') ?? 0) > 0 ? 'danger' : undefined, filter: { key: 'level', op: '=', value: 'error' } },
                  { k: 'Traces', v: count(n('traces')) },
                  { k: 'Per min', v: count(n('perMinute')) },
              ]
              : [
                  { k: signal === 'requests' ? 'Requests' : 'Spans', v: count(n('count')) },
                  { k: 'Error rate', v: percent(n('errorRate')), tone: (n('errorRate') ?? 0) > 0.01 ? 'danger' : undefined, filter: { key: 'status', op: '=', value: 'error' }, title: 'Show only failed' },
                  { k: 'p50', v: ms(n('p50')) },
                  { k: 'p95', v: ms(p95), tone: (p95 ?? 0) > 1000 ? 'warn' : undefined, filter: p95 ? { key: 'duration', op: '>=', value: `${Math.floor(p95)}ms` } : undefined, title: 'Show the slowest 5%' },
                  { k: 'Traces', v: count(n('traces')) },
              ];

    return (
        <div className="t-rstats">
            {items.map((i) => {
                const body = (
                    <>
                        <span className="k">{i.k}</span>
                        <span className={`v ${i.tone ? `t-tone-${i.tone}` : ''}`}>{i.v}</span>
                    </>
                );
                if (!i.filter) return <div key={i.k} className="t-rstat">{body}</div>;
                const f = i.filter;
                const on = where.some((w) => { const p = parseFilter(w); return p?.key === f.key && p.op === f.op && (f.op !== '=' || p.value === f.value); });
                return (
                    <button
                        key={i.k}
                        type="button"
                        className={`t-rstat is-link ${on ? 'is-on' : ''}`}
                        title={on ? 'Remove this filter' : `${i.title ?? 'Filter to these'} · ${formatFilter(f)}`}
                        onClick={() => onWhere(on ? where.filter((w) => { const p = parseFilter(w); return !(p?.key === f.key && p.op === f.op); }) : toggleFilter(where, f))}
                    >
                        {body}
                    </button>
                );
            })}
            <span className="t-pill" title={sample.exact ? 'Exact' : 'Computed over the newest matching results'}>
                {sample.exact ? 'exact' : `${sample.truncated ? 'newest ' : ''}${count(sample.size)} ${sample.truncated ? 'sampled' : 'results'}`}
            </span>
            {sample.readSideFiltered && (
                <span className="t-pill t-pill-warn" title="The backend can't evaluate one of the filters (e.g. !~), so it was applied to a wider sample after fetching — counts may be low.">
                    filtered after sampling
                </span>
            )}
        </div>
    );
}
