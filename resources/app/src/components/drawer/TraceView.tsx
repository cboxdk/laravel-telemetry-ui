import { useMemo, useState } from 'react';
import { useTrace } from '../../api/hooks';
import type { ReportItem, SpanData, TraceData } from '../../api/types';
import { count, ms, shortId, statusTone } from '../../lib/format';
import { Go } from '../../lib/links';
import { useBoot, DimensionValue } from '../DimensionValue';
import { Sparkline } from '../charts/Sparkline';
import { CopyButton } from '../CopyButton';
import { Icon } from '../Icon';
import { Empty, ErrorState, Skeleton } from '../States';

type Tab = 'story' | 'waterfall' | 'logs' | 'context' | 'profile';

/**
 * The trace story. Leads with what happened (request, dimensions, the
 * report: queries, cache, outgoing calls) and the correlation — surrounding
 * metrics vs baseline, the trace's logs, the profile — with the waterfall one
 * tab away and raw attributes behind each span.
 */
export function TraceView({ traceId, full }: { traceId: string; full?: boolean }) {
    const { data, error, isLoading } = useTrace(traceId);
    const [tab, setTab] = useState<Tab>('story');

    if (isLoading) return <div className="t-pad"><Skeleton height={320} /></div>;
    if (error || !data) return <div className="t-pad"><ErrorState error={error} /></div>;

    const root = data.root;
    const status = root?.attributes['http.response.status_code'];
    const tabs: { key: Tab; label: string; n?: number }[] = [
        { key: 'story', label: 'Story' },
        { key: 'waterfall', label: 'Waterfall', n: data.spanCount },
        { key: 'logs', label: 'Logs', n: data.logs.length },
        { key: 'context', label: 'Context', n: data.context.filter((c) => c.outlier).length || undefined },
        ...(data.profile.length > 0 ? [{ key: 'profile' as Tab, label: 'Profile' }] : []),
    ];

    return (
        <div className={`t-trace ${full ? 'is-full' : ''}`}>
            <div className="t-dhead">
                <div className="t-dhead-top">
                    {status ? <span className={`t-status t-status-${statusTone(status)}`}>{status}</span> : data.error ? <span className="t-status t-status-danger">ERROR</span> : <span className="t-status t-status-ok">OK</span>}
                    <h2 className="mono">{root?.name ?? 'Trace'}</h2>
                    <span className="t-dhead-dur mono">{ms(data.durationMs)}</span>
                </div>
                <div className="t-dhead-meta">
                    <span className="mono t-dim" title={data.traceId}>trace {shortId(data.traceId, 16)}</span>
                    <CopyButton text={data.traceId} label="ID" />
                    {data.chain.length > 1 && (
                        <span className="t-chain">
                            {data.chain.map((hop, i) => (
                                <span key={hop.spanId} className="t-chain-hop">
                                    {i > 0 && <Icon name="chevronRight" size={11} />}
                                    <span className="mono">{hop.service}</span>
                                    <span className="t-dim mono">{ms(hop.durationMs)}</span>
                                </span>
                            ))}
                        </span>
                    )}
                </div>
                <div className="t-tabs" role="tablist">
                    {tabs.map((t) => (
                        <button key={t.key} type="button" role="tab" aria-selected={tab === t.key} className={tab === t.key ? 'is-on' : ''} onClick={() => setTab(t.key)}>
                            {t.label}{t.n ? <span className="t-tab-n">{t.n}</span> : null}
                        </button>
                    ))}
                </div>
            </div>
            <div className="t-dbody">
                {tab === 'story' && <Story data={data} />}
                {tab === 'waterfall' && <Waterfall data={data} />}
                {tab === 'logs' && <Logs data={data} />}
                {tab === 'context' && <Context data={data} />}
                {tab === 'profile' && <Profile data={data} />}
            </div>
        </div>
    );
}

function Story({ data }: { data: TraceData }) {
    const boot = useBoot();
    const root = data.root;
    // Declared dimensions can be stamped on any span; the root wins on conflict.
    const attrs = useMemo(() => Object.assign({}, ...[...data.waterfall].reverse().map((r) => r.span.attributes), root?.attributes ?? {}) as Record<string, string>, [data, root]);
    const declared = boot.dimensions.filter((d) => !d.builtin && attrs[d.key]);
    const builtins = ['http.route', 'user.id', 'client.address', 'host.name', 'service.name'].filter((k) => attrs[k]);
    const report = data.report;
    const errorSpans = data.waterfall.filter((r) => r.span.error).map((r) => r.span);

    return (
        <div className="t-story-trace">
            {(data.exceptions.length > 0 || errorSpans.length > 0) && (
                <div className="t-why">
                    <div className="t-why-title">Why it failed</div>
                    {data.exceptions.map((e) => (
                        <div key={e.group || e.type} className="t-why-exc">
                            <p>
                                <code>{e.type}</code> {e.message}
                                {e.file && <span className="t-dim mono"> · {e.file.split('/').slice(-2).join('/')}{e.line ? `:${e.line}` : ''}</span>}
                            </p>
                            <div className="t-row-gap">
                                {e.group && <Go link={{ to: 'error', group: e.group }} className="t-linkbtn">Open issue →</Go>}
                                {e.match === 'time' && <span className="t-pill" title="The exception record carries no trace id; matched by service and time window">likely match</span>}
                            </div>
                        </div>
                    ))}
                    {data.exceptions.length === 0 && errorSpans.slice(0, 3).map((s) => (
                        <p key={s.spanId}><code>{s.name}</code> failed{s.attributes['exception.type'] ? <> with <code>{s.attributes['exception.type']}</code></> : null}{s.attributes['exception.message'] ? `: ${s.attributes['exception.message']}` : ''} <span className="t-dim">({ms(s.durationMs)})</span></p>
                    ))}
                </div>
            )}

            {report.totals.length > 0 && (
                <div className="t-redrow">
                    {report.totals.slice(0, 6).map((t) => (
                        <div key={t.label} className="t-tile"><span className="k">{t.label}</span><span className="v">{t.value}</span></div>
                    ))}
                </div>
            )}

            {(declared.length > 0 || builtins.length > 0) && (
                <section className="t-sect">
                    <h4 className="t-sect-title">Dimensions <span>· filter / open</span></h4>
                    {declared.map((d) => (
                        <div key={d.key} className="t-dimchip">
                            <span className="lbl">{d.label}</span>
                            <DimensionValue dimKey={d.key} value={attrs[d.key]!} linkOut={data.dimensionLinks[d.key]} />
                            {data.dimensionLinks[d.key] && <a className="t-dimchip-out" href={data.dimensionLinks[d.key]} target="_blank" rel="noopener noreferrer">open ↗</a>}
                        </div>
                    ))}
                    <div className="t-chips">
                        {builtins.map((k) => <DimensionValue key={k} dimKey={k} value={attrs[k]!} chip label />)}
                    </div>
                </section>
            )}

            {Object.keys(report.request).length > 0 && (
                <section className="t-sect">
                    <h4 className="t-sect-title">Request</h4>
                    <dl className="t-kv">
                        {Object.entries(report.request).map(([k, v]) => <div key={k} className="t-kv-row"><dt>{k}</dt><dd className="mono">{v}</dd></div>)}
                    </dl>
                </section>
            )}

            <ReportSection title="Database" items={report.db.items} duplicates={report.db.duplicates} icon="database" />
            <ReportSection title="Cache" items={report.cache.items} summary={report.cache.summary} icon="zap" />
            <ReportSection title="Redis" items={report.redis} icon="database" />
            <ReportSection title="Outgoing" items={report.outgoing} icon="globe" />
            <ReportSection title="Queued jobs" items={report.queued} icon="layers" />
            <ReportSection title="Views" items={report.views} icon="file" />
            <ReportSection title="Storage" items={report.storage} icon="box" />

            {data.logs.length > 0 && (
                <section className="t-sect">
                    <h4 className="t-sect-title">Logs <span>· {data.logs.length}</span></h4>
                    <Logs data={{ ...data, logs: data.logs.slice(0, 5) }} />
                </section>
            )}
            {data.context.some((c) => c.outlier) && (
                <section className="t-sect">
                    <h4 className="t-sect-title">Unusual around this request</h4>
                    <Context data={{ ...data, context: data.context.filter((c) => c.outlier) }} />
                </section>
            )}
        </div>
    );
}

function ReportSection({ title, items, duplicates, summary, icon }: { title: string; items: ReportItem[]; duplicates?: Record<string, number>; summary?: Record<string, number>; icon: string }) {
    if (items.length === 0) return null;
    const total = items.reduce((s, i) => s + i.durationMs, 0);
    const dupes = Object.entries(duplicates ?? {}).filter(([, n]) => n > 1);
    return (
        <section className="t-sect">
            <h4 className="t-sect-title"><Icon name={icon} size={12} /> {title} <span>· {items.length} · {ms(total)}</span></h4>
            {summary && Object.keys(summary).length > 0 && <div className="t-chips">{Object.entries(summary).map(([k, v]) => <span key={k} className="t-minchip"><span className="k">{k}</span>{v}</span>)}</div>}
            {dupes.length > 0 && <div className="t-callout t-callout-warn"><p>{dupes.length} duplicated quer{dupes.length === 1 ? 'y' : 'ies'} — N+1 suspect.</p></div>}
            <div className="t-report">
                {items.slice(0, 12).map((item, i) => (
                    <div key={`${item.spanId}-${i}`} className="t-report-row">
                        <span className="mono t-report-detail" title={item.detail}>{item.detail || item.name}</span>
                        <span className="mono t-dim">{ms(item.durationMs)}</span>
                    </div>
                ))}
                {items.length > 12 && <div className="t-dim t-report-more">+ {items.length - 12} more in the waterfall</div>}
            </div>
        </section>
    );
}

function Waterfall({ data }: { data: TraceData }) {
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
    const [selected, setSelected] = useState<string | null>(null);
    const rows = useMemo(() => data.waterfall.filter((r) => !r.ancestors.some((a) => collapsed.has(a))), [data.waterfall, collapsed]);
    const span = data.waterfall.find((r) => r.span.spanId === selected)?.span;

    return (
        <div className="t-waterfall-wrap">
            <div className="t-waterfall">
                {rows.map((row) => {
                    const s = row.span;
                    const identity = data.identities[s.service];
                    return (
                        <div key={s.spanId} className={`t-wf-row ${s.error ? 'is-error' : ''} ${selected === s.spanId ? 'is-sel' : ''}`} onClick={() => setSelected(selected === s.spanId ? null : s.spanId)}>
                            <div className="t-wf-name" style={{ paddingLeft: 6 + row.depth * 12 }}>
                                {row.children > 0 ? (
                                    <button type="button" className="t-wf-toggle" onClick={(e) => { e.stopPropagation(); setCollapsed((c) => { const n = new Set(c); if (n.has(s.spanId)) n.delete(s.spanId); else n.add(s.spanId); return n; }); }}>
                                        <Icon name={collapsed.has(s.spanId) ? 'chevronRight' : 'chevronDown'} size={11} />
                                    </button>
                                ) : <span className="t-wf-toggle" />}
                                <span className="t-wf-dot" style={{ background: identity?.color }} title={s.service} />
                                {s.browser && <span className="t-badge t-badge-warn">web</span>}
                                <span className="mono t-ellipsis">{s.name}</span>
                                {s.summary && <span className="t-wf-summary mono t-ellipsis">{s.summary}</span>}
                            </div>
                            <div className="t-wf-track">
                                <span className="t-wf-bar" style={{ left: `${row.offsetPct}%`, width: `${row.widthPct}%` }} />
                                <span className="t-wf-dur mono">{ms(s.durationMs)}</span>
                            </div>
                        </div>
                    );
                })}
            </div>
            {span && <SpanDetail span={span} links={data.dimensionLinks} onClose={() => setSelected(null)} />}
        </div>
    );
}

function SpanDetail({ span, links, onClose }: { span: SpanData; links: Record<string, string>; onClose: () => void }) {
    return (
        <div className="t-spandetail">
            <div className="t-spandetail-head">
                <strong className="mono">{span.name}</strong>
                <span className="t-dim mono">{span.kind} · {span.service} · {ms(span.durationMs)}</span>
                <button type="button" className="t-iconbtn" onClick={onClose}><Icon name="x" size={13} /></button>
            </div>
            {span.links.length > 0 && (
                <div className="t-chips">{span.links.map((l) => <Go key={l.spanId} link={{ to: 'trace', id: l.traceId }} className="t-minchip">linked {shortId(l.traceId)}</Go>)}</div>
            )}
            <dl className="t-kv t-raw">
                {Object.entries(span.attributes).map(([k, v]) => (
                    <div key={k} className="t-kv-row"><dt className="mono">{k}</dt><dd><DimensionValue dimKey={k} value={v} linkOut={links[k]} /></dd></div>
                ))}
            </dl>
        </div>
    );
}

function Logs({ data }: { data: TraceData }) {
    if (data.logs.length === 0) return <Empty>No log lines carry this trace id.</Empty>;
    return (
        <div className="t-tracelogs">
            {data.logsMatch === 'time' && <p className="t-note">These lines carry no trace id — shown because the same service logged them during this request.</p>}
            {data.logs.map((l, i) => (
                <div key={i} className={`t-log t-log-${l.tone}`}>
                    <div className="t-log-line is-static">
                        <span className="t-log-time mono">{l.time}</span>
                        <span className={`t-log-level t-tone-${l.tone}`}>{l.level.toUpperCase().slice(0, 5)}</span>
                        <span className="t-log-msg mono">{l.message}</span>
                    </div>
                </div>
            ))}
        </div>
    );
}

function Context({ data }: { data: TraceData }) {
    if (data.context.length === 0) return <Empty>No surrounding metrics for this trace's scope.</Empty>;
    const fmt = (v: number, unit: string) => (unit === 'ms' ? ms(v) : unit === 'percent' || unit === 'ratio' ? `${Math.round(v * (unit === 'ratio' ? 100 : 1))}%` : count(v));
    return (
        <div className="t-context">
            {data.context.map((c) => (
                <div key={c.label} className={`t-context-row ${c.outlier ? 'is-outlier' : ''}`}>
                    <span className="t-context-label">{c.label}<span className="t-dim"> · {c.group}</span></span>
                    <Sparkline points={c.points} tone={c.outlier ? 'danger' : 'info'} width={110} height={24} />
                    <span className="mono">{fmt(c.current, c.unit)}</span>
                    <span className="t-dim mono">{c.baseline !== null ? `usual ${fmt(c.baseline, c.unit)}` : '—'}</span>
                </div>
            ))}
        </div>
    );
}

function Profile({ data }: { data: TraceData }) {
    return (
        <div className="t-profile">
            {data.profile.map((p) => (
                <div key={p.name} className="t-profile-row">
                    <span className="t-profile-bar"><i style={{ width: `${p.percent}%` }} /></span>
                    <span className="mono t-ellipsis">{p.name}</span>
                    <span className="mono t-dim">{Math.round(p.percent)}% · {p.count}</span>
                </div>
            ))}
        </div>
    );
}
