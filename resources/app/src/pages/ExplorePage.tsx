import { Link, useParams } from '@tanstack/react-router';
import { useMemo, useState } from 'react';
import { useExplore, useFacets } from '../api/hooks';
import type { ErrorRow, LogEntryRow, Signal, SpanRow } from '../api/types';
import { Combobox } from '../components/Combobox';
import { useBoot } from '../components/DimensionValue';
import { ErrorState, Empty, Skeleton } from '../components/States';
import { FacetPanel } from '../components/explore/FacetPanel';
import { FilterBar } from '../components/explore/FilterBar';
import { LogList } from '../components/explore/LogList';
import { ErrorList, GroupTable, SpanList } from '../components/explore/Results';
import { HeatmapChart } from '../components/charts/HeatmapChart';
import { TimeChart } from '../components/charts/TimeChart';
import { Icon } from '../components/Icon';
import { count, ms, percent } from '../lib/format';
import { useLiveTail } from '../lib/liveTail';
import { useTitle } from '../lib/title';
import { list, parseDrawer, scopeOf, str } from '../lib/search';
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
    const boot = useBoot();
    const search = useSearchState();
    const set = useSetSearch();
    const scope = useScope();
    const [live, setLive] = useState(false);
    useTitle(TITLES[signal], 'Explore');

    const where = list(search, 'where');
    const q = str(search, 'q');
    const groupBy = str(search, 'groupBy');
    const facetKeys = list(search, 'facets');
    const top = parseDrawer(str(search, 'drawer')).at(-1);

    const params = useMemo(() => ({ where, q, groupBy }), [where.join('|'), q, groupBy]); // eslint-disable-line react-hooks/exhaustive-deps
    const explore = useExplore<SpanRow | LogEntryRow | ErrorRow>(signal, params);
    const facets = useFacets(signal, useMemo(() => ({ where, q, keys: facetKeys.length ? [...defaultKeys(boot, signal), ...facetKeys] : [] }), [where.join('|'), q, facetKeys.join('|'), signal])); // eslint-disable-line react-hooks/exhaustive-deps

    const data = explore.data;
    const streamable = signal === 'logs' || signal === 'requests';
    const newest = data?.rows[0] as (SpanRow & LogEntryRow) | undefined;
    const since = newest ? (newest.nano ?? String((newest.startMs ?? 0) * 1_000_000)) : null;
    const tail = useLiveTail<SpanRow | LogEntryRow>(signal === 'logs' ? 'logs' : 'requests', { ...scope, where, q }, live && streamable, since);

    const rows = useMemo(() => (data ? [...tail.rows, ...data.rows] : []), [data, tail.rows]);
    const groupOptions = boot.dimensions.filter((d) => d.scope !== 'intrinsic' && (signal !== 'errors')).map((d) => ({ value: d.key, label: d.label, hint: d.key }));

    return (
        <div className="t-explore-page">
            <div className="t-xbar">
                <div className="t-xbar-row">
                    <h1 className="t-page-title">{TITLES[signal]}</h1>
                    <div className="t-seg t-signal-tabs" role="tablist">
                        {boot.explore.map((s) => (
                            <Link key={s.signal} to={`/explore/${s.signal}`} search={{ ...scopeOf(search), where } as never} className={s.signal === signal ? 'is-on' : ''} role="tab" aria-selected={s.signal === signal}>
                                {s.label}
                            </Link>
                        ))}
                    </div>
                    <div className="t-topbar-spacer" />
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
                                <StatsLine signal={signal} stats={data.stats} sample={data.sample} />
                                {signal !== 'errors' && (
                                    <div className="t-groupby">
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
                                        <HeatmapChart xs={data.heatmap.xs} ys={data.heatmap.ys} cells={data.heatmap.cells} height={130} />
                                    </div>
                                )}
                            </div>

                            {data.groupBy && data.groups && data.groups.length > 0 && <GroupTable groupKey={data.groupBy} groups={data.groups} exact={data.sample.groupsExact} />}

                            {rows.length === 0 ? (
                                <Empty>Nothing matches in this window. Widen the period or remove a filter.</Empty>
                            ) : signal === 'logs' ? (
                                <LogList rows={rows as LogEntryRow[]} />
                            ) : signal === 'errors' ? (
                                <ErrorList rows={rows as unknown as ErrorRow[]} />
                            ) : (
                                <SpanList rows={rows as SpanRow[]} selected={top?.type === 'trace' ? top.id : undefined} />
                            )}
                        </>
                    )}
                </section>
            </div>
        </div>
    );
}

function defaultKeys(boot: ReturnType<typeof useBoot>, signal: Signal): string[] {
    return boot.dimensions.filter((d) => !d.builtin || d.signals.includes(signal)).map((d) => d.key);
}

function StatsLine({ signal, stats, sample }: { signal: Signal; stats: Record<string, unknown>; sample: { size: number; truncated: boolean; exact: boolean; readSideFiltered?: boolean } }) {
    const n = (k: string) => (typeof stats[k] === 'number' ? (stats[k] as number) : null);
    const items: { k: string; v: string; tone?: string }[] =
        signal === 'errors'
            ? [
                { k: 'Events', v: count(n('count')) },
                { k: 'Issues', v: count(n('groups')) },
                { k: 'Users hit', v: count(n('users')) },
                { k: 'Frontend', v: count(n('frontend')) },
            ]
            : signal === 'logs'
              ? [
                  { k: 'Lines', v: count(n('count')) },
                  { k: 'Errors', v: count(n('errors')), tone: (n('errors') ?? 0) > 0 ? 'danger' : undefined },
                  { k: 'Traces', v: count(n('traces')) },
                  { k: 'Per min', v: count(n('perMinute')) },
              ]
              : [
                  { k: signal === 'requests' ? 'Requests' : 'Spans', v: count(n('count')) },
                  { k: 'Error rate', v: percent(n('errorRate')), tone: (n('errorRate') ?? 0) > 0.01 ? 'danger' : undefined },
                  { k: 'p50', v: ms(n('p50')) },
                  { k: 'p95', v: ms(n('p95')), tone: (n('p95') ?? 0) > 1000 ? 'warn' : undefined },
                  { k: 'Traces', v: count(n('traces')) },
              ];

    return (
        <div className="t-rstats">
            {items.map((i) => (
                <div key={i.k} className="t-rstat">
                    <span className="k">{i.k}</span>
                    <span className={`v ${i.tone ? `t-tone-${i.tone}` : ''}`}>{i.v}</span>
                </div>
            ))}
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
