import { useState } from 'react';
import type {
    BarsPayload, CalloutPayload, ChartPayload, CodePayload, CompositePayload, GraphPayload, HeaderPayload,
    HeatmapPayload, KvPayload, LogsPayload, PanelPayload, Stat, StatsPayload, TablePayload, TicketDraft,
} from '../../api/types';
import { Go, useGo } from '../../lib/links';
import { count } from '../../lib/format';
import { DimensionValue } from '../DimensionValue';
import { Sparkline } from '../charts/Sparkline';
import { TimeChart } from '../charts/TimeChart';
import { HeatmapChart } from '../charts/HeatmapChart';
import { GraphChart } from '../charts/GraphChart';
import { Empty } from '../States';
import { DataTable } from './DataTable';
import { LogList } from '../explore/LogList';

export interface BodyProps {
    onParam?: (params: Record<string, string>) => void;
    onTicket?: (draft: TicketDraft) => void;
}

const TONES = new Set(['ok', 'warn', 'danger', 'dim', 'info']);

export function StatTiles({ items, compact }: { items: Stat[]; compact?: boolean }) {
    if (items.length === 0) return null;
    return (
        <div className={`t-stats ${compact ? 'is-compact' : ''}`}>
            {items.map((s, i) => (
                <div key={`${s.label}-${i}`} className="t-stat">
                    <span className="t-stat-k">{s.label}</span>
                    <span className={`t-stat-v ${s.tone && TONES.has(s.tone) ? `t-tone-${s.tone}` : ''}`}>{s.value}</span>
                    {s.delta && <span className={`t-stat-delta t-tone-${s.deltaTone ?? 'dim'}`}>{s.delta}</span>}
                    {s.points && s.points.length > 1 && <Sparkline points={s.points} width={90} height={22} />}
                </div>
            ))}
        </div>
    );
}

/** Renders one payload by kind. Unknown kinds say so instead of vanishing. */
export function PanelBody({ data, onParam, onTicket }: { data: PanelPayload } & BodyProps) {
    if (data.error) return <div className="t-panel-error" role="alert">{data.error}</div>;

    switch (data.kind) {
        case 'chart': return <ChartBody data={data} />;
        case 'table': return <TableBody data={data} onParam={onParam} onTicket={onTicket} />;
        case 'bars': return <BarsBody data={data} />;
        case 'stats': return <StatsBody data={data} />;
        case 'composite': return <CompositeBody data={data} onParam={onParam} onTicket={onTicket} />;
        case 'header': return <HeaderBody data={data} />;
        case 'kv': return <KvBody data={data} />;
        case 'code': return <CodeBody data={data} />;
        case 'callout': return <CalloutBody data={data} />;
        case 'heatmap': return <HeatmapBody data={data} />;
        case 'graph': return <GraphBody data={data} />;
        case 'logs': return <LogsBody data={data} />;
        case 'hidden': return null;
        default: return <Empty>Unsupported panel kind “{(data as { kind: string }).kind}”.</Empty>;
    }
}

function ChartBody({ data }: { data: ChartPayload }) {
    const hasData = data.series.some((s) => s.data.length > 0);
    return (
        <>
            {data.stats && <StatTiles items={data.stats} compact />}
            {hasData ? (
                <TimeChart series={data.series} type={data.type} unit={data.unit} height={data.height ?? 200} annotations={data.annotations} min={data.min} max={data.max} />
            ) : (
                <Empty>{data.empty ?? 'No data in this window.'}</Empty>
            )}
        </>
    );
}

function TableBody({ data, onParam, onTicket }: { data: TablePayload } & BodyProps) {
    if (data.rows.length === 0) return <Empty>{data.empty ?? 'Nothing in this window.'}</Empty>;
    return <DataTable columns={data.columns} rows={data.rows} onParam={onParam} onTicket={onTicket} />;
}

function BarsBody({ data }: { data: BarsPayload }) {
    if (data.items.length === 0) return <Empty>{data.empty ?? 'Nothing in this window.'}</Empty>;
    const max = Math.max(1, ...data.items.map((i) => i.value));
    return (
        <ul className="t-bars">
            {data.items.map((item, i) => {
                const inner = (
                    <>
                        <span className="t-bar-fill" style={{ width: `${(item.value / max) * 100}%` }} data-tone={item.tone} />
                        <span className="t-bar-label">{item.label}</span>
                        {item.sub && <span className="t-bar-sub">{item.sub}</span>}
                        <span className="t-bar-value">{item.display ?? count(item.value)}</span>
                    </>
                );
                return (
                    <li key={`${item.label}-${i}`}>
                        {item.dim ? (
                            <DimensionValue dimKey={item.dim.key} value={item.dim.value}><span className="t-bar">{inner}</span></DimensionValue>
                        ) : item.link ? (
                            <Go link={item.link} className="t-bar">{inner}</Go>
                        ) : (
                            <div className="t-bar">{inner}</div>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

function StatsBody({ data }: { data: StatsPayload }) {
    const badge = typeof data.badge === 'string' ? { label: data.badge } : data.badge;
    return (
        <>
            {badge && <span className={`t-badge ${badge.tone ? `t-badge-${badge.tone}` : ''}`} title={badge.title}>{badge.label}</span>}
            {data.items.length > 0 ? <StatTiles items={data.items} /> : <Empty>{data.empty ?? 'No data.'}</Empty>}
        </>
    );
}

function CompositeBody({ data, onParam, onTicket }: { data: CompositePayload } & BodyProps) {
    const parts = data.parts.filter((p) => p.kind !== 'hidden');
    if (parts.length === 0) return <Empty>{data.empty ?? 'Nothing in this window.'}</Empty>;
    return (
        <div className="t-composite">
            {parts.map((part, i) => (
                <section key={i} className="t-part">
                    {part.title && part.kind !== 'callout' && part.kind !== 'header' && <h4 className="t-part-title">{part.title}{part.subtitle && <span> · {part.subtitle}</span>}</h4>}
                    <PanelBody data={part} onParam={onParam} onTicket={onTicket} />
                    {part.note && <p className="t-note">{part.note}</p>}
                </section>
            ))}
        </div>
    );
}

function HeaderBody({ data }: { data: HeaderPayload }) {
    const back = data.back ?? data.drill ?? null;
    return (
        <div className="t-entity-head">
            {back && <Go link={back} className="t-back">{data.backLabel ?? back.label ?? '← Back'}</Go>}
            {data.badges && data.badges.length > 0 && <div className="t-badges">{data.badges.map((b) => <span key={b} className="t-badge">{b}</span>)}</div>}
            <StatTiles items={data.stats} />
            {data.links && data.links.length > 0 && (
                <div className="t-row-gap">{data.links.map((l, i) => <Go key={i} link={l} className="t-btn t-btn-sm t-btn-secondary">{l.label ?? 'Open'}</Go>)}</div>
            )}
        </div>
    );
}

function KvBody({ data }: { data: KvPayload }) {
    if (data.items.length === 0) return <Empty>{data.empty ?? 'Nothing to show.'}</Empty>;
    return (
        <dl className="t-kv">
            {data.items.map((item, i) => (
                <div key={`${item.label}-${i}`} className="t-kv-row">
                    <dt>{item.label}</dt>
                    <dd className={`${item.mono ? 'mono' : ''} ${item.tone && TONES.has(item.tone) ? `t-tone-${item.tone}` : ''}`}>
                        {item.link ? <Go link={item.link}>{String(item.value ?? '—')}</Go> : String(item.value ?? '—')}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export function CodeBody({ data }: { data: Pick<CodePayload, 'text' | 'language' | 'highlight'> }) {
    const lines = data.text.split('\n');
    const highlight = new Set(data.highlight ?? []);
    const [wrap, setWrap] = useState(false);
    return (
        <div className="t-code-wrap">
            <button type="button" className="t-code-toggle" onClick={() => setWrap((w) => !w)}>{wrap ? 'No wrap' : 'Wrap'}</button>
            <pre className={`t-code ${wrap ? 'is-wrap' : ''}`} data-lang={data.language}>
                {lines.map((line, i) => (
                    <span key={i} className={`t-code-line ${highlight.has(i + 1) ? 'is-hl' : ''}`}>
                        {line || ' '}
                        {'\n'}
                    </span>
                ))}
            </pre>
        </div>
    );
}

function CalloutBody({ data }: { data: CalloutPayload }) {
    return (
        <div className={`t-callout t-callout-${data.tone ?? 'info'}`}>
            {data.title && data.kind === 'callout' && <strong>{data.title}</strong>}
            <p>{data.message}</p>
        </div>
    );
}

function HeatmapBody({ data }: { data: HeatmapPayload }) {
    if (data.cells.length === 0) return <Empty>{data.empty ?? 'No data in this window.'}</Empty>;
    return <HeatmapChart xs={data.xs} ys={data.ys} cells={data.cells} unit={data.unit} />;
}

function GraphBody({ data }: { data: GraphPayload }) {
    const go = useGo();
    if (data.nodes.length === 0) return <Empty>{data.empty ?? 'No service calls in this window.'}</Empty>;
    return <GraphChart data={data} onNode={(link) => go(link)} />;
}

function LogsBody({ data }: { data: LogsPayload }) {
    if (data.entries.length === 0) return <Empty>{data.empty ?? 'No log lines in this window.'}</Empty>;
    return <LogList rows={data.entries} height={420} />;
}
