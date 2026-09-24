import { useIssue } from '../../api/hooks';
import { ago } from '../../lib/format';
import { Go } from '../../lib/links';
import { Icon } from '../Icon';
import { Markdown } from '../Markdown';
import { ErrorState, Loading } from '../States';

export function IssueView({ id }: { id: string }) {
    const { data, error, isLoading } = useIssue(id);
    if (isLoading) return <div className="t-pad"><Loading label="Loading the issue…" height={240} /></div>;
    if (error || !data) return <div className="t-pad"><ErrorState error={error} /></div>;

    return (
        <div className="t-issue">
            <div className="t-dhead">
                <div className="t-dhead-top">
                    <span className={`t-badge ${data.open ? 't-badge-ok' : 't-badge-dim'}`}>{data.state}</span>
                    <h2>{data.title}</h2>
                </div>
                <div className="t-dhead-meta">
                    <span className="mono t-dim">#{data.id.replace(/^#/, '')}</span>
                    {data.author && <span className="t-dim">by {data.author}</span>}
                    {data.updatedAt && <span className="t-dim">updated {ago(Date.parse(data.updatedAt))}</span>}
                    {data.url && <a href={data.url} target="_blank" rel="noopener noreferrer" className="t-btn t-btn-sm t-btn-secondary">Open in tracker <Icon name="external" size={12} /></a>}
                </div>
                {data.labels.length > 0 && <div className="t-chips">{data.labels.map((l) => <span key={l} className="t-badge">{l}</span>)}</div>}
            </div>
            <div className="t-dbody">
                {data.traceIds.length > 0 && (
                    <section className="t-sect">
                        <h4 className="t-sect-title">Linked traces</h4>
                        <div className="t-chips">{data.traceIds.map((t) => <Go key={t} link={{ to: 'trace', id: t }} className="t-minchip mono">{t.slice(0, 12)}</Go>)}</div>
                    </section>
                )}
                {data.body ? <Markdown text={data.body} /> : <p className="t-dim">No description.</p>}
            </div>
        </div>
    );
}
