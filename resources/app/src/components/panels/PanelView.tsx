import { useMemo, useState } from 'react';
import { usePanel } from '../../api/hooks';
import type { Control, TicketDraft } from '../../api/types';
import { Go } from '../../lib/links';
import { useSearchState, useSetSearch } from '../../lib/state';
import { Combobox } from '../Combobox';
import { ComposeIssue } from '../ComposeIssue';
import { CopyButton } from '../CopyButton';
import { Icon } from '../Icon';
import { ErrorState, Skeleton } from '../States';
import { PanelBody } from './PanelBody';

/** URL keys that are never panel params. */
const RESERVED = new Set(['period', 'from', 'to', 'service', 'env', 'refresh', 'drawer', 'where', 'groupBy', 'facets', 'value', 'tab', 'limit']);

/** Controls each panel declared on its last response — which URL keys it reads. */
const knownControls = new Map<string, string[]>();

/**
 * One panel: fetched on its own (a slow backend query never blocks the page),
 * framed as a Cbox DataPanel with its controls, drill link, live toggle and
 * typed error/empty states.
 */
export function PanelView({ id, span = 1, params = {} }: { id: string; span?: number; params?: Record<string, string> }) {
    const search = useSearchState();
    const set = useSetSearch();
    const [live, setLive] = useState(false);
    const [draft, setDraft] = useState<TicketDraft | null>(null);

    const controlParams = useMemo(() => {
        const keys = knownControls.get(id);
        const out: Record<string, string> = {};
        for (const [k, v] of Object.entries(search)) {
            if (typeof v !== 'string' || RESERVED.has(k)) continue;
            if (keys === undefined || keys.includes(k)) out[k] = v;
        }
        return out;
    }, [id, search]);

    const { data, error, isLoading, isFetching } = usePanel(id, { ...controlParams, ...params }, live);

    if (data?.controls) knownControls.set(id, data.controls.map((c) => c.param));

    if (data?.kind === 'hidden') return null;

    const effectiveSpan = Math.min(3, Math.max(1, data?.span ?? span));
    const setParam = (patch: Record<string, string>) => set(Object.fromEntries(Object.entries(patch).map(([k, v]) => [k, v === '' ? undefined : v])));

    const isHeader = data?.kind === 'header';

    return (
        <section className={`t-panel span-${effectiveSpan} ${isHeader ? 'is-header' : ''} ${isFetching && !isLoading ? 'is-refreshing' : ''}`} aria-busy={isFetching}>
            {(data?.title || !data) && (
                <header className="t-panel-head">
                    <div className="t-panel-titles">
                        {isHeader ? <h1 className="t-page-title">{data?.title}</h1> : <h3 className="t-panel-title">{data?.title ?? ' '}</h3>}
                        {data?.subtitle && <p className="t-panel-sub">{data.subtitle}</p>}
                    </div>
                    <div className="t-panel-actions">
                        {data?.controls?.map((c) => <ControlView key={c.param} control={c} value={controlParams[c.param] ?? c.value} onChange={(v) => setParam({ [c.param]: v })} />)}
                        {data?.stream && (
                            <button type="button" className={`t-btn t-btn-sm ${live ? 't-btn-live' : 't-btn-ghost'}`} onClick={() => setLive((l) => !l)} title="Live tail — refresh every 3s">
                                <Icon name={live ? 'pause' : 'play'} size={12} />
                                {live ? 'Live' : 'Tail'}
                            </button>
                        )}
                        {data?.copy && <CopyButton text={data.copy.text} label={data.copy.label} />}
                        {data?.ticket && <button type="button" className="t-btn t-btn-sm t-btn-secondary" onClick={() => setDraft(data.ticket ?? null)}><Icon name="plus" size={12} />Create issue</button>}
                        {data?.drill && <Go link={data.drill} className="t-drill">{data.drill.label ?? 'Open'} <Icon name="chevronRight" size={12} /></Go>}
                    </div>
                </header>
            )}
            <div className="t-panel-body">
                {/* Every state renders something: data, else the typed error, else a skeleton (loading or retrying). */}
                {data ? <PanelBody data={data} onParam={setParam} onTicket={setDraft} /> : error && !isFetching ? <ErrorState error={error} compact /> : <Skeleton height={160} />}
            </div>
            {data?.note && <footer className="t-panel-foot">{data.note}</footer>}
            {draft && <ComposeIssue draft={draft} onClose={() => setDraft(null)} />}
        </section>
    );
}

function ControlView({ control, value, onChange }: { control: Control; value: string; onChange: (v: string) => void }) {
    const [text, setText] = useState(value);

    if (control.type === 'select') {
        return <Combobox label={control.label} value={value} options={control.options ?? []} onChange={onChange} className="t-combo-sm" />;
    }

    return (
        <form className={`t-search-control ${control.placeholder && control.placeholder !== control.label ? 'has-label' : ''}`} onSubmit={(e) => { e.preventDefault(); onChange(text.trim()); }}>
            {/* A placeholder is an example, not a label: say what the box filters. */}
            {control.placeholder && control.placeholder !== control.label
                ? <span className="t-search-label">{control.label}</span>
                : <Icon name="search" size={12} />}
            <input
                className="t-input t-input-sm"
                value={text}
                placeholder={control.placeholder || control.label}
                aria-label={control.label}
                onChange={(e) => setText(e.target.value)}
                onBlur={() => text.trim() !== value && onChange(text.trim())}
            />
        </form>
    );
}
