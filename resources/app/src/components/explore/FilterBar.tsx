import { useMemo, useRef, useState, type KeyboardEvent } from 'react';
import type { DimensionDef } from '../../api/types';
import { count } from '../../lib/format';
import { formatFilter, negate, parseFilter, withoutFilter, type Filter } from '../../lib/search';
import { ValueText } from '../DimensionValue';
import { Icon } from '../Icon';

/**
 * The active query as removable chips — the URL *is* the query. Click a
 * chip's operator to invert it; ✕ removes it; "+ filter" takes `key=value`
 * (any operator: = != =~ !~ > >= < <=) with key suggestions from the
 * dimension registry and — once you type `key=` — the values actually present
 * in this view, with their counts. Undeclared attributes are just as
 * filterable.
 */
export function FilterBar({ where, onChange, dimensions, q, onQ, placeholder, facetValues }: {
    where: string[];
    onChange: (where: string[]) => void;
    dimensions: DimensionDef[];
    q: string;
    onQ: (q: string) => void;
    placeholder?: string;
    /** Values seen for a key in this view, for the value typeahead. */
    facetValues?: (key: string) => { value: string; count: number }[];
}) {
    const [adding, setAdding] = useState(false);
    const [draft, setDraft] = useState('');
    const [text, setText] = useState(q);
    const [active, setActive] = useState(0);
    const input = useRef<HTMLInputElement>(null);

    const byKey = useMemo(() => new Map(dimensions.map((d) => [d.key, d])), [dimensions]);

    // `user.id=` → offer the values in this view; otherwise offer keys.
    const partial = /^\s*([\w.:\-]+)\s*(=|!=)\s*(.*)$/.exec(draft);
    const valueSuggestions = useMemo(() => {
        if (!partial || !facetValues) return [];
        const typed = partial[3]!.trim().toLowerCase();
        return facetValues(partial[1]!)
            .filter((v) => typed === '' || v.value.toLowerCase().includes(typed))
            .slice(0, 8);
    }, [partial?.[1], partial?.[3], facetValues]); // eslint-disable-line react-hooks/exhaustive-deps

    const suggestions = useMemo(() => {
        const typed = draft.trim().toLowerCase();
        if (/[=!<>~]/.test(typed)) return [];
        return dimensions
            .filter((d) => typed === '' || d.key.toLowerCase().includes(typed) || d.label.toLowerCase().includes(typed))
            .sort((a, b) => Number(a.builtin) - Number(b.builtin))
            .slice(0, 8);
    }, [dimensions, draft]);

    const commit = (raw: string) => {
        const f = parseFilter(raw);
        if (!f) return;
        onChange([...where.filter((w) => w !== formatFilter(f)), formatFilter(f)]);
        setDraft('');
        setAdding(false);
    };

    const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const value = valueSuggestions[active];
            if (value && partial) commit(`${partial[1]}${partial[2]}${value.value}`);
            else if (parseFilter(draft)) commit(draft);
            else if (suggestions[active]) setDraft(`${suggestions[active]!.key}=`);
        } else if (e.key === 'Escape') {
            setAdding(false);
            setDraft('');
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((a) => Math.min((valueSuggestions.length > 0 ? valueSuggestions.length : suggestions.length) - 1, a + 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((a) => Math.max(0, a - 1));
        } else if (e.key === 'Backspace' && draft === '' && where.length > 0) {
            onChange(where.slice(0, -1));
        }
    };

    const flip = (raw: string, f: Filter) => {
        onChange(where.map((w) => (w === raw ? formatFilter({ ...f, op: negate(f.op) }) : w)));
    };

    return (
        <div className="t-qbuild">
            <span className="t-qbuild-lead mono">where</span>
            {where.map((raw) => {
                const f = parseFilter(raw);
                if (!f) return null;
                const dim = byKey.get(f.key);
                const neg = f.op === '!=' || f.op === '!~';
                return (
                    <span key={raw} className={`t-chip ${dim && !dim.builtin ? 'is-custom' : ''} ${neg ? 'is-neg' : ''}`} title={raw}>
                        <span className="k">{dim?.label ?? f.key}</span>
                        <button type="button" className="op" onClick={() => flip(raw, f)} title="Invert">{f.op}</button>
                        <b>{f.value === '' ? '∅' : f.op === '=' || f.op === '!=' ? <ValueText dimKey={f.key} value={f.value} /> : f.value}</b>
                        <button type="button" className="x" onClick={() => onChange(withoutFilter(where, raw))} aria-label={`Remove ${raw}`}><Icon name="x" size={11} /></button>
                    </span>
                );
            })}
            {adding ? (
                <span className="t-chip-add">
                    <input
                        ref={input}
                        autoFocus
                        className="mono"
                        value={draft}
                        placeholder="key=value"
                        onChange={(e) => { setDraft(e.target.value); setActive(0); }}
                        onKeyDown={onKey}
                        onBlur={() => setTimeout(() => { if (draft === '') setAdding(false); }, 150)}
                        aria-label="Add filter"
                    />
                    {valueSuggestions.length > 0 && partial && (
                        <ul className="t-suggest">
                            {valueSuggestions.map((v, i) => (
                                <li key={v.value}>
                                    <button type="button" className={i === active ? 'is-active' : ''} onMouseDown={(e) => { e.preventDefault(); commit(`${partial[1]}${partial[2]}${v.value}`); }}>
                                        <span><ValueText dimKey={partial[1]!} value={v.value} /></span>
                                        <code>{count(v.count)}</code>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {valueSuggestions.length === 0 && suggestions.length > 0 && (
                        <ul className="t-suggest">
                            {suggestions.map((d, i) => (
                                <li key={d.key}>
                                    <button type="button" className={i === active ? 'is-active' : ''} onMouseDown={(e) => { e.preventDefault(); setDraft(`${d.key}=`); input.current?.focus(); }}>
                                        <span className={!d.builtin ? 't-violet' : ''}>{d.label}</span>
                                        <code>{d.key}</code>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </span>
            ) : (
                <button type="button" className="t-add" onClick={() => setAdding(true)}><Icon name="plus" size={12} />filter</button>
            )}
            <form className="t-qbuild-search" onSubmit={(e) => { e.preventDefault(); onQ(text.trim()); }}>
                <Icon name="search" size={13} />
                <input value={text} onChange={(e) => setText(e.target.value)} onBlur={() => text.trim() !== q && onQ(text.trim())} placeholder={placeholder ?? 'Search…'} aria-label="Search" />
            </form>
            {(where.length > 0 || q) && (
                <button type="button" className="t-btn t-btn-ghost t-btn-sm" onClick={() => { onChange([]); setText(''); onQ(''); }}>Clear</button>
            )}
        </div>
    );
}
