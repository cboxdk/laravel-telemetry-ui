import { useState } from 'react';
import type { Facet, FacetsResult } from '../../api/types';
import { count } from '../../lib/format';
import { formatFilter, parseFilter, toggleFilter } from '../../lib/search';
import { ValueText } from '../DimensionValue';
import { Icon } from '../Icon';
import { Skeleton } from '../States';

/**
 * Datadog/Honeycomb-style facet sidebar: every dimension with its top values
 * and counts. Click a value to filter to it, ⌥-click to exclude it; "group"
 * breaks the view down by that dimension. Host-declared dimensions lead, in
 * their own groups.
 */
export function FacetPanel({ data, loading, where, onWhere, onGroupBy, groupBy, extraKeys, onAddKey, onRemoveKey }: {
    data?: FacetsResult;
    loading: boolean;
    where: string[];
    onWhere: (where: string[]) => void;
    onGroupBy: (key: string) => void;
    groupBy: string;
    extraKeys: string[];
    onAddKey: (key: string) => void;
    onRemoveKey: (key: string) => void;
}) {
    const [adding, setAdding] = useState('');
    // On narrow screens the facets stack above the results: folded by default,
    // one tap to open — never a small scroll box.
    const [folded, setFolded] = useState(true);

    if (loading && !data) {
        return <aside className="t-facets"><Skeleton height={300} /></aside>;
    }

    // Facets with no values in this sample collapse into one quiet line at the
    // bottom (unless you added them yourself or they're filtered on).
    const all = data?.facets ?? [];
    const isEmpty = (f: Facet) => f.values.length === 0 && !extraKeys.includes(f.key) && !where.some((w) => parseFilter(w)?.key === f.key);
    const facets = all.filter((f) => !isEmpty(f));
    const empty = all.filter(isEmpty);
    const groups = new Map<string, Facet[]>();
    for (const f of facets) {
        const g = f.custom ? f.group ?? 'Custom' : f.group ?? 'Other';
        groups.set(g, [...(groups.get(g) ?? []), f]);
    }
    const ordered = [...groups.entries()].sort(([, a], [, b]) => Number(b[0]?.custom ?? false) - Number(a[0]?.custom ?? false));

    const active = new Set(where);

    return (
        <aside className={`t-facets ${folded ? 'is-folded' : ''}`} aria-label="Facets">
            <div className="t-facets-meta">
                <button type="button" className="t-facets-fold" onClick={() => setFolded((f) => !f)} aria-expanded={!folded}>
                    <Icon name={folded ? 'chevronRight' : 'chevronDown'} size={13} />Facets{where.length > 0 ? ` · ${where.length} active` : ''}
                </button>
                {data && (data.exact ? <span className="t-pill t-pill-ok" title="Counts cover every match">exact</span> : <span className="t-pill" title="Counted over the newest matching spans">sample · {count(data.sample)}</span>)}
            </div>
            {ordered.map(([group, list]) => (
                <div key={group} className={`t-facet-group ${list[0]?.custom ? 'is-custom' : ''}`}>
                    <div className="t-facet-group-title"><span>{group}</span>{list[0]?.custom && <span className="t-tag">custom</span>}</div>
                    {list.map((facet) => {
                        const max = Math.max(1, ...facet.values.map((v) => v.count));
                        return (
                            <div key={facet.key} className="t-facet">
                                <div className="t-facet-title">
                                    <span title={facet.key}>{facet.label}</span>
                                    <span className="t-facet-actions">
                                        <button type="button" className={groupBy === facet.key ? 'is-on' : ''} onClick={() => onGroupBy(groupBy === facet.key ? '' : facet.key)} title={`Group by ${facet.label}`}><Icon name="group" size={11} /></button>
                                        {extraKeys.includes(facet.key) && <button type="button" onClick={() => onRemoveKey(facet.key)} title="Remove facet"><Icon name="x" size={11} /></button>}
                                    </span>
                                </div>
                                {facet.values.length === 0 && <div className="t-facet-none">no values</div>}
                                {facet.values.map((v) => {
                                    const raw = formatFilter({ key: facet.key, op: '=', value: v.value });
                                    const neg = formatFilter({ key: facet.key, op: '!=', value: v.value });
                                    const selected = active.has(raw);
                                    const excluded = active.has(neg);
                                    return (
                                        <button
                                            type="button"
                                            key={v.value}
                                            className={`t-fv ${selected ? 'is-sel' : ''} ${excluded ? 'is-neg' : ''}`}
                                            onClick={(e) => onWhere(toggleFilter(where, { key: facet.key, op: e.altKey ? '!=' : '=', value: v.value }))}
                                            title={`${facet.key} = ${v.value} · ⌥-click to exclude`}
                                        >
                                            <span className="t-fv-bar" style={{ width: `${(v.count / max) * 100}%` }} />
                                            <span className="t-fv-val mono"><ValueText dimKey={facet.key} value={v.value} /></span>
                                            <span className="t-fv-cnt mono">{count(v.count)}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        );
                    })}
                </div>
            ))}
            {empty.length > 0 && (
                <p className="t-facet-empty" title="No values in this sample">
                    No values: {empty.map((f) => f.label).join(' · ')}
                </p>
            )}
            <form
                className="t-facet-add"
                onSubmit={(e) => {
                    e.preventDefault();
                    const key = adding.trim();
                    if (key !== '' && !parseFilter(key)) onAddKey(key);
                    setAdding('');
                }}
            >
                <input className="t-input t-input-sm mono" value={adding} onChange={(e) => setAdding(e.target.value)} placeholder="+ facet (attribute key)" aria-label="Add facet" />
            </form>
        </aside>
    );
}
