import { Link, useParams } from '@tanstack/react-router';
import { useState } from 'react';
import { useEntityIndex, useEntityStory } from '../api/hooks';
import type { EntityStory, Link as LinkData, SpanRow } from '../api/types';
import { useBoot, useDimension, DimensionValue, useDrill, useValueLabel, ValueText } from '../components/DimensionValue';
import { Empty, ErrorState, Skeleton } from '../components/States';
import { PanelView } from '../components/panels/PanelView';
import { fillRows } from './PanelPage';
import { HeatmapChart } from '../components/charts/HeatmapChart';
import { TimeChart } from '../components/charts/TimeChart';
import { SpanList } from '../components/explore/Results';
import { Icon } from '../components/Icon';
import { ago, clock, count, ms, percent, statusTone } from '../lib/format';
import { Go, useGo } from '../lib/links';
import { scopeOf, str } from '../lib/search';
import { useSearchState, useSetSearch } from '../lib/state';
import { useTitle } from '../lib/title';

/** Every value of an entity type (routes, queries, customers…) with RED. */
export function EntityIndexPage() {
    const { type } = useParams({ strict: false }) as { type: string };

    return <EntityIndexView type={type} />;
}

/** Every value of one entity type. */
export function EntityIndexView({ type }: { type: string }) {
    const boot = useBoot();
    const search = useSearchState();
    const [filter, setFilter] = useState('');
    const { data, error, isLoading } = useEntityIndex(type);
    const def = boot.entities.find((e) => e.type === type);
    useTitle(def?.plural ?? type);

    const values = (data?.values ?? []).filter((v) => filter === '' || v.value.toLowerCase().includes(filter.toLowerCase()));
    const max = Math.max(1, ...values.map((v) => v.count));

    return (
        <div className="t-page">
            <header className="t-page-head">
                <div>
                    <div className="t-eyebrow">{def?.custom ? def.group ?? 'Custom' : 'Entities'}</div>
                    <h1 className="t-page-title">{data?.entity.plural ?? def?.plural ?? type}</h1>
                    <p className="t-page-sub mono">{data?.entity.key ?? def?.key}</p>
                </div>
                <div className="t-row-gap">
                    <input className="t-input t-input-sm" placeholder={`Filter ${(def?.plural ?? type).toLowerCase()}…`} value={filter} onChange={(e) => setFilter(e.target.value)} />
                    <Link
                        to={`/explore/${data?.signal ?? 'requests'}`}
                        search={{ ...scopeOf(search), groupBy: data?.entity.key ?? def?.key, ...(data?.signal === 'traces' ? { where: [`${data.entity.key}!=`] } : {}) } as never}
                        className="t-btn t-btn-sm t-btn-secondary"
                    >
                        <Icon name="group" size={12} />Explore grouped
                    </Link>
                </div>
            </header>

            <section className="t-panel span-3">
                {isLoading && !data ? <Skeleton height={300} /> : error ? <ErrorState error={error} /> : values.length === 0 ? (
                    <Empty>No {(def?.plural ?? type).toLowerCase()} seen in this window.</Empty>
                ) : (
                    <div className="t-groups t-entity-index">
                        <div className="t-groups-head">
                            <span>{data?.entity.label}</span>
                            <span className="is-num">Count</span>
                            <span className="is-num">Error rate</span>
                            <span className="is-num">Avg</span>
                            <span className="is-num">P95</span>
                        </div>
                        {values.map((v) => (
                            <Go key={v.value} link={{ to: 'entity', type, value: v.value }} className="t-groups-row is-link">
                                <span className="t-groups-val"><i style={{ width: `${(v.count / max) * 100}%` }} /><span className="mono"><ValueText dimKey={data?.entity.key ?? ''} value={v.value} /></span></span>
                                <span className="is-num mono">{count(v.count)}</span>
                                <span className={`is-num mono ${v.errorRate > 0.01 ? 't-tone-danger' : 't-dim'}`}>{percent(v.errorRate)}</span>
                                <span className="is-num mono">{ms(v.avg)}</span>
                                <span className="is-num mono">{ms(v.p95)}</span>
                            </Go>
                        ))}
                    </div>
                )}
                {data && <footer className="t-panel-foot">{data.unit === 'spans' ? `Counted over ${count(data.sample.size)} occurrences in the newest matching traces.` : data.sample.truncated ? `Counted over the newest ${count(data.sample.size)} matching requests.` : `${count(data.sample.size)} requests in this window.`}</footer>}
            </section>
        </div>
    );
}

/**
 * One entity's story: what it is, how it's doing, why it's failing, who it
 * hits, what changed — raw attributes last, behind a tab.
 */
export function EntityPage() {
    const { type } = useParams({ strict: false }) as { type: string };

    return <EntityView type={type} />;
}

/** One entity's story. The value comes from the view state (`?value=`). */
export function EntityView({ type }: { type: string }) {
    const search = useSearchState();
    const set = useSetSearch();
    const value = str(search, 'value');
    const tab = str(search, 'tab') || 'story';
    const { data, error, isLoading } = useEntityStory(type, value);
    const dim = useDimension(data?.entity.key ?? '');
    const name = useValueLabel(data?.entity.key ?? '', value);
    useTitle(name ?? value, data?.entity.label ?? dim?.label ?? type);

    if (value === '') return <div className="t-page"><Empty>Pick a value from the {type} list.</Empty></div>;

    return (
        <div className="t-page">
            <header className="t-page-head t-entity-header">
                <div className="t-entity-titles">
                    <div className="t-eyebrow">
                        <Link to={`/entities/${encodeURIComponent(type)}`} search={scopeOf(search) as never}>{data?.entity.plural ?? dim?.plural ?? type}</Link>
                        <span> / {data?.entity.label ?? dim?.label ?? type}</span>
                    </div>
                    {name
                        ? <><h1 className="t-page-title t-entity-value">{name}</h1><p className="t-page-sub mono">{value}</p></>
                        : <h1 className="t-page-title mono t-entity-value">{value}</h1>}
                </div>
                <div className="t-row-gap">
                    {data?.entity.linkOut && <a className="t-btn t-btn-sm t-btn-primary" href={data.entity.linkOut} target="_blank" rel="noopener noreferrer">Open in app <Icon name="external" size={12} /></a>}
                    <Go link={{ to: 'explore', signal: data?.signal ?? 'requests', where: data?.where ?? [] }} className="t-btn t-btn-sm t-btn-secondary"><Icon name="compass" size={12} />Explore</Go>
                    <Go link={{ to: 'explore', signal: 'logs', where: [`${(data?.entity.key ?? type).replace(/[.-]/g, '_')}=${value}`] }} className="t-btn t-btn-sm t-btn-ghost">Logs</Go>
                </div>
            </header>

            <div className="t-tabs" role="tablist">
                {(['story', ...(data && data.panels.length > 0 ? ['metrics'] : []), 'raw'] as const).map((t) => (
                    <button key={t} type="button" role="tab" aria-selected={tab === t} className={tab === t ? 'is-on' : ''} onClick={() => set({ tab: t === 'story' ? undefined : t }, { replace: true })}>
                        {t === 'story' ? 'Story' : t === 'metrics' ? 'Metrics' : 'Raw attributes'}
                    </button>
                ))}
            </div>

            {isLoading && !data ? <Skeleton height={400} /> : error && !data ? <ErrorState error={error} /> : data ? (
                tab === 'raw' ? <RawTab data={data} /> : tab === 'metrics' ? (
                    // The entity header above already names it: skip the detail page's own header.
                    <div className="t-grid">{fillRows(data.panels.filter((p) => !p.id.endsWith('-header'))).map((p) => <PanelView key={p.id} id={p.id} span={p.span} params={p.params} />)}</div>
                ) : <StoryTab data={data} />
            ) : null}
        </div>
    );
}

function StoryTab({ data }: { data: EntityStory }) {
    const drill = useDrill();
    const go = useGo();
    const red = data.red;
    const explore = (extra: string[] = [], params?: Record<string, string>): LinkData => ({ to: 'explore', signal: data.signal, where: [...data.where, ...extra], ...(params ? { params } : {}) });

    if (data.sample.size === 0) {
        return <Empty>No spans for this {data.entity.label.toLowerCase()} in this window. Try a longer period.</Empty>;
    }

    return (
        <div className="t-story">
            <div className="t-insights">
                {data.insights.map((ins, i) => (
                    <div key={i} className={`t-insight t-insight-${ins.tone}`}>
                        <Icon name={ins.tone === 'danger' ? 'alert' : ins.tone === 'warn' ? 'zap' : ins.tone === 'ok' ? 'sparkle' : 'activity'} size={14} />
                        <span>{ins.text}</span>
                        {ins.dim && <button type="button" className="t-linkbtn" onClick={() => drill.filter(ins.dim!.key, ins.dim!.value)}>filter →</button>}
                        {ins.link && <Go link={ins.link} className="t-linkbtn">open →</Go>}
                    </div>
                ))}
            </div>

            <div className="t-redrow">
                <Tile k={data.signal === 'requests' ? 'Requests' : 'Occurrences'} v={count(red.count)} sub={`${count(red.perMinute)}/min`} link={explore()} />
                <Tile k="Error rate" v={percent(red.errorRate)} tone={red.errorRate > 0.01 ? 'danger' : undefined} sub={data.signal === 'requests' ? `${count(red.errors)} server errors` : `${count(red.errors)} failed`} link={red.errors > 0 ? explore(['status=error']) : undefined} />
                <Tile k="p50" v={ms(red.p50)} link={red.p50 ? explore([`duration>=${Math.floor(red.p50)}ms`]) : undefined} />
                <Tile k="p95" v={ms(red.p95)} tone={(red.p95 ?? 0) > 1000 ? 'warn' : undefined} link={red.p95 ? explore([`duration>=${Math.floor(red.p95)}ms`]) : undefined} />
                <Tile k="Traces" v={count(red.traces)} link={{ to: 'explore', signal: 'traces', where: data.where }} />
            </div>

            <section className="t-panel span-3">
                <header className="t-panel-head"><h3 className="t-panel-title">Trend</h3><p className="t-panel-sub">Throughput, failures and p95 — deploys marked</p></header>
                <div className="t-panel-body t-trend">
                    <TimeChart type="stacked" height={150} min={data.range.start} max={data.range.end} annotations={data.deploys} series={[
                        { name: 'ok', data: data.series.count.map(([t, c], i) => [t, c - (data.series.errors[i]?.[1] ?? 0)]), color: 'var(--chart-1)' },
                        { name: 'errors', data: data.series.errors, color: 'var(--chart-4)' },
                    ]} />
                    <TimeChart height={150} unit="ms" min={data.range.start} max={data.range.end} annotations={data.deploys} series={[{ name: 'p95', data: data.series.p95, color: 'var(--chart-3)' }]} />
                </div>
                {data.heatmap.cells.length > 0 && (
                    <div className="t-panel-body t-trend-heat">
                        <div className="t-cap">Latency × time · click a cell to open those {data.signal === 'requests' ? 'requests' : 'spans'}</div>
                        <HeatmapChart
                            xs={data.heatmap.xs}
                            ys={data.heatmap.ys}
                            cells={data.heatmap.cells}
                            height={120}
                            unit={data.signal === 'requests' ? 'requests' : 'spans'}
                            onCell={(x, y) => {
                                const from = data.heatmap.xs[x];
                                const band = data.heatmap.bands?.[y];
                                const width = data.heatmap.width;
                                if (from === undefined || !band || !width) return;
                                go(explore(
                                    [...(band[0] > 0 ? [`duration>=${band[0]}ms`] : []), ...(band[1] !== null ? [`duration<${band[1]}ms`] : [])],
                                    { from: String(Math.floor(from / 1000)), to: String(Math.ceil((from + width) / 1000)) },
                                ));
                            }}
                        />
                    </div>
                )}
            </section>

            <div className="t-story-cols">
                <section className="t-panel">
                    <header className="t-panel-head"><h3 className="t-panel-title">Who and where</h3><p className="t-panel-sub">Top values per dimension · red = over-represented in failures</p></header>
                    <div className="t-panel-body t-breakdowns">
                        {data.breakdowns.length === 0 && <Empty>No dimensions on these spans.</Empty>}
                        {data.breakdowns.map((b) => (
                            <div key={b.key} className={`t-breakdown ${b.custom ? 'is-custom' : ''}`}>
                                <div className="t-breakdown-title"><span>{b.label}</span><span className="t-dim mono">{b.distinct} distinct</span></div>
                                {b.values.map((v) => (
                                    <div key={v.value} className="t-breakdown-row">
                                        {b.drill === false
                                            ? <Go link={calledFrom(v.value)} className="mono t-ellipsis t-linkish" title={`${v.value} · open`}>{v.value}</Go>
                                            : <DimensionValue dimKey={b.key} value={v.value}><span className="mono">{v.value}</span></DimensionValue>}
                                        <span className="t-breakdown-bar"><i style={{ width: `${v.share * 100}%` }} className={v.lift !== null && v.lift > 1.5 && v.failing > 0 ? 'is-hot' : ''} /></span>
                                        <span className="mono t-dim">{count(v.count)}</span>
                                        {v.failing > 0 && <span className="mono t-tone-danger" title="failing">{v.failing}✕</span>}
                                    </div>
                                ))}
                            </div>
                        ))}
                    </div>
                </section>

                <div className="t-stack">
                    {data.statusMix.some((st) => st.value !== '(none)') && (
                    <section className="t-panel">
                        <header className="t-panel-head"><h3 className="t-panel-title">Status</h3></header>
                        <div className="t-panel-body t-statusmix">
                            {data.statusMix.filter((s) => s.value !== '(none)').map((s) => (
                                <button key={s.value} type="button" className="t-statusmix-item" onClick={() => drill.filter('http.response.status_code', s.value)}>
                                    <span className={`t-status t-status-${statusTone(s.value)}`}>{s.value}</span>
                                    <span className="mono">{count(s.count)}</span>
                                    <span className="t-dim mono">{percent(s.share)}</span>
                                </button>
                            ))}
                        </div>
                    </section>
                    )}
                    <section className="t-panel">
                        <header className="t-panel-head"><h3 className="t-panel-title">Correlated</h3></header>
                        <div className="t-panel-body t-corr">
                            {data.errors.map((e) => (
                                <Go key={e.group} link={{ to: 'error', group: e.group }} className="t-corr-item">
                                    <span className="t-dot t-dot-danger" />
                                    <span><strong className="mono">{e.type}</strong> {e.message}</span>
                                    <span className="t-dim mono">{e.count}× →</span>
                                </Go>
                            ))}
                            {data.deploys.slice(-4).reverse().map((d, i) => (
                                <div key={i} className="t-corr-item">
                                    <span className="t-dot t-dot-violet" />
                                    <span>{d.kind === 'deploy' ? 'Deploy' : d.kind} <strong className="mono">{d.label}</strong></span>
                                    <span className="t-dim mono">{ago(d.xAxis)}</span>
                                </div>
                            ))}
                            {data.errors.length === 0 && data.deploys.length === 0 && <span className="t-dim">No exceptions or deploys in this window.</span>}
                        </div>
                    </section>
                </div>
            </div>

            <div className="t-story-cols">
                <TraceTable title="Failing" rows={data.failing} empty="No failures — nice." onOpen={(r) => go({ to: 'trace', id: r.traceId })} />
                <TraceTable title="Slowest" rows={data.slowest} empty="No spans." onOpen={(r) => go({ to: 'trace', id: r.traceId })} />
            </div>

            {data.recent.length > 0 && (
                <section className="t-panel span-3">
                    <header className="t-panel-head">
                        <div className="t-panel-titles">
                            <h3 className="t-panel-title">Recent</h3>
                            <p className="t-panel-sub">Newest {count(data.recent.length)} · click one for its trace</p>
                        </div>
                        <Go link={explore()} className="t-linkbtn">All in Explore →</Go>
                    </header>
                    <div className="t-panel-body t-tight"><SpanList rows={data.recent} /></div>
                </section>
            )}

            <p className="t-note">{data.sample.truncated ? `Story computed over the newest ${count(data.sample.size)} matching spans.` : `Story computed over ${count(data.sample.size)} spans in this window.`}</p>
        </div>
    );
}

/**
 * "Called from" values are trace root names: `GET /users/{id}` is a route
 * (open its page); anything else (a job, a command) is a span-name search.
 */
function calledFrom(root: string): LinkData {
    const m = /^(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS) (\/\S*)$/.exec(root);
    return m ? { to: 'entity', type: 'route', value: m[2]! } : { to: 'explore', signal: 'traces', where: [], params: { q: root } };
}

function Tile({ k, v, sub, tone, link }: { k: string; v: string; sub?: string; tone?: string; link?: LinkData }) {
    const body = (
        <>
            <span className="k">{k}{link && <Icon name="chevronRight" size={11} />}</span>
            <span className={`v ${tone ? `t-tone-${tone}` : ''}`}>{v}</span>
            {sub && <span className="s">{sub}</span>}
        </>
    );
    return link ? <Go link={link} className="t-tile is-link">{body}</Go> : <div className="t-tile">{body}</div>;
}

function TraceTable({ title, rows, empty, onOpen }: { title: string; rows: SpanRow[]; empty: string; onOpen: (r: SpanRow) => void }) {
    return (
        <section className="t-panel">
            <header className="t-panel-head"><h3 className="t-panel-title">{title}</h3></header>
            <div className="t-panel-body t-tight">
                {rows.length === 0 ? <Empty>{empty}</Empty> : rows.map((r, i) => (
                    <button key={`${r.traceId}-${i}`} type="button" className="t-mini-row" onClick={() => onOpen(r)}>
                        <span className="mono t-dim">{clock(r.startMs)}</span>
                        {r.status && <span className={`t-status t-status-${statusTone(r.status)}`}>{r.status}</span>}
                        <span className="mono t-ellipsis" title={r.name}>
                            {r.method ? `${r.method} ` : ''}{r.path ?? r.target ?? r.attributes['trace.root'] ?? r.name}
                            {!r.method && r.attributes['trace.root'] && r.attributes['trace.root'] !== r.name ? <span className="t-dim"> · {r.name}</span> : null}
                        </span>
                        <span className="mono">{ms(r.durationMs)}</span>
                    </button>
                ))}
            </div>
        </section>
    );
}

function RawTab({ data }: { data: EntityStory }) {
    const entries = Object.entries(data.raw);
    return (
        <section className="t-panel span-3">
            <div className="t-rawnote"><Icon name="alert" size={14} /><span>Every attribute of the newest span, last resort. Click a value to filter or group by it.</span></div>
            {entries.length === 0 ? <Empty>No attributes.</Empty> : (
                <dl className="t-kv t-raw">
                    {entries.map(([k, v]) => (
                        <div key={k} className="t-kv-row">
                            <dt className="mono">{k}</dt>
                            <dd><DimensionValue dimKey={k} value={v} /></dd>
                        </div>
                    ))}
                </dl>
            )}
        </section>
    );
}
