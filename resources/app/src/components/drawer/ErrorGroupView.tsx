import { useState } from 'react';
import { useErrorGroup } from '../../api/hooks';
import { ago, count } from '../../lib/format';
import { Go } from '../../lib/links';
import { ComposeIssue } from '../ComposeIssue';
import { CopyButton } from '../CopyButton';
import { Icon } from '../Icon';
import { Empty, ErrorState, Skeleton } from '../States';
import { CodeBody } from '../panels/PanelBody';

/**
 * The Sentry-style issue in the drawer: what, how often, since when, the
 * suspect deploy, the release spread, the latest request, the stacktrace —
 * plus file-an-issue and copy-for-LLM.
 */
export function ErrorGroupView({ group }: { group: string }) {
    const { data, error, isLoading } = useErrorGroup(group);
    const [composing, setComposing] = useState(false);

    if (isLoading) return <div className="t-pad"><Skeleton height={320} /></div>;
    if (error || !data) return <div className="t-pad"><ErrorState error={error} /></div>;

    const d = data.detail;
    const s = data.stats;
    const lines = d?.source ? d.source.split('\n') : [];

    return (
        <div className="t-errgroup">
            <div className="t-dhead">
                <div className="t-dhead-top">
                    <span className="t-status t-status-danger">{s?.source ?? 'error'}</span>
                    <h2 className="mono">{d?.type ?? 'Unknown error'}</h2>
                </div>
                {d?.message && <p className="t-err-msg">{d.message}</p>}
                <div className="t-dhead-meta">
                    <Go link={{ to: 'page', page: 'error-detail', params: { group } }} className="t-btn t-btn-sm t-btn-secondary">Full issue page <Icon name="chevronRight" size={12} /></Go>
                    {data.canCreateIssue && data.draft && <button type="button" className="t-btn t-btn-sm t-btn-secondary" onClick={() => setComposing(true)}><Icon name="plus" size={12} />Create issue{data.tracker ? ` in ${data.tracker}` : ''}</button>}
                    <CopyButton text={data.llm} label="Copy for LLM" />
                </div>
            </div>
            <div className="t-dbody">
                {s ? (
                    <div className="t-redrow">
                        <div className="t-tile"><span className="k">Events</span><span className="v">{count(s.count)}{s.sampled ? '+' : ''}</span></div>
                        <div className="t-tile"><span className="k">Users</span><span className="v">{count(s.users)}</span></div>
                        <div className="t-tile"><span className="k">First seen</span><span className="v t-small">{s.firstSeen}</span></div>
                        <div className="t-tile"><span className="k">Last seen</span><span className="v t-small">{s.lastSeen}</span></div>
                    </div>
                ) : <Empty>No occurrences in the last {data.lookbackDays} days.</Empty>}

                {data.suspect && (
                    <div className="t-why">
                        <div className="t-why-title">Suspect: {data.suspect.kind} {data.suspect.label}</div>
                        <p>First seen {data.suspect.gap} after it ({data.suspect.time}).{data.suspect.notes ? ` ${data.suspect.notes}` : ''}</p>
                        {data.suspect.traceId && <Go link={{ to: 'trace', id: data.suspect.traceId }} className="t-linkbtn">deploy trace →</Go>}
                    </div>
                )}

                {data.request && (
                    <section className="t-sect">
                        <h4 className="t-sect-title">Latest occurrence</h4>
                        <Go link={{ to: 'trace', id: data.request.traceId }} className="t-corr-item">
                            <span className="t-dot t-dot-danger" />
                            <span className="mono">{data.request.method} {data.request.route || data.request.origin}</span>
                            <span className="t-dim mono">{data.request.status}{data.request.user ? ` · user ${data.request.user}` : ''} →</span>
                        </Go>
                    </section>
                )}

                {data.releases.length > 0 && (
                    <section className="t-sect">
                        <h4 className="t-sect-title">Releases</h4>
                        <div className="t-chips">{data.releases.map((r) => <span key={r.release} className="t-minchip mono"><span className="k">{r.release || '—'}</span>{r.count}</span>)}</div>
                    </section>
                )}

                {d?.source && (
                    <section className="t-sect">
                        <h4 className="t-sect-title mono">{d.file}:{d.line}</h4>
                        <CodeBody data={{ text: d.source, language: 'php', highlight: lines.flatMap((l, i) => (l.startsWith('> ') ? [i + 1] : [])) }} />
                    </section>
                )}

                {d?.stacktrace && (
                    <section className="t-sect">
                        <h4 className="t-sect-title">Stacktrace</h4>
                        <CodeBody data={{ text: d.stacktrace }} />
                    </section>
                )}

                {data.occurrences.length > 0 && (
                    <section className="t-sect">
                        <h4 className="t-sect-title">Occurrences <span>· newest {Math.min(20, data.occurrences.length)}</span></h4>
                        {data.occurrences.slice(0, 20).map((o, i) => (
                            <Go key={`${o.traceId}-${i}`} link={{ to: 'trace', id: o.traceId }} className="t-mini-row">
                                <span className="mono t-dim">{ago(o.nano / 1e6)}</span>
                                <span className={`t-badge ${o.frontend ? 't-badge-warn' : 't-badge-info'}`}>{o.frontend ? 'web' : 'server'}</span>
                                <span className="t-ellipsis">{o.message}</span>
                                <span className="mono t-dim">{o.service}</span>
                            </Go>
                        ))}
                    </section>
                )}
            </div>
            {composing && data.draft && <ComposeIssue draft={data.draft} onClose={() => setComposing(false)} />}
        </div>
    );
}
