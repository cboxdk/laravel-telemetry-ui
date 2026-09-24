import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import type { DimensionDef, Signal } from '../../api/types';
import { count } from '../../lib/format';
import { formatFilter, parseFilter, withoutFilter, type Filter, type Op } from '../../lib/search';
import { ValueText } from '../DimensionValue';
import { Icon } from '../Icon';

/**
 * The active query as removable chips — the URL *is* the query. Click a chip
 * to edit it in place (operator, value); ✕ removes it; "+ filter" takes `key=value`
 * (any operator: = != =~ !~ > >= < <=) with key suggestions from the
 * dimension registry and — once you type `key=` — the values actually present
 * in this view, with their counts. Undeclared attributes are just as
 * filterable.
 */
/**
 * Fields you filter on that aren't dimensions (nothing to facet or group by):
 * span intrinsics the backend compares as tokens. `op` is what picking one
 * starts with; `presets` are offered as values.
 */
interface Field {
    key: string;
    label: string;
    op: string;
    hint: string;
    presets?: string[];
}

const FIELDS: Partial<Record<Signal, Field[]>> = {
    requests: [
        { key: 'duration', label: 'Duration', op: '>', hint: 'duration > 400ms', presets: ['100ms', '250ms', '500ms', '1s', '2s', '5s'] },
    ],
    traces: [
        { key: 'duration', label: 'Duration', op: '>', hint: 'duration > 400ms', presets: ['100ms', '250ms', '500ms', '1s', '2s', '5s'] },
        { key: 'kind', label: 'Span kind', op: '=', hint: 'kind', presets: ['server', 'client', 'internal', 'producer', 'consumer'] },
    ],
};

type Suggestion = { key: string; label: string; hint: string; op: string; custom: boolean };

export function FilterBar({ where, onChange, dimensions, signal, q, onQ, placeholder, facetValues }: {
    where: string[];
    onChange: (where: string[]) => void;
    dimensions: DimensionDef[];
    /** Offers only the keys this signal has, plus its fields (duration…). */
    signal?: Signal;
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
    const [editing, setEditing] = useState<string | null>(null);
    const input = useRef<HTMLInputElement>(null);

    const byKey = useMemo(() => new Map(dimensions.map((d) => [d.key, d])), [dimensions]);
    const fields = useMemo(() => (signal ? FIELDS[signal] ?? [] : Object.values(FIELDS).flat().filter((f, i, all) => all.findIndex((g) => g.key === f.key) === i)), [signal]);
    const fieldByKey = useMemo(() => new Map(fields.map((f) => [f.key, f])), [fields]);

    // `user.id=` → offer the values in this view (or a field's presets,
    // `duration>` → 250ms, 1s…); otherwise offer keys.
    const partial = /^\s*([\w.:\-]+)\s*(!=|=~|!~|>=|<=|=|>|<)\s*(.*)$/.exec(draft);
    const valueSuggestions = useMemo(() => {
        if (!partial) return [];
        const typed = partial[3]!.trim().toLowerCase();
        const matches = (v: string) => typed === '' || v.toLowerCase().includes(typed);
        const presets = fieldByKey.get(partial[1]!)?.presets;
        if (presets) return presets.filter(matches).map((value) => ({ value, count: null as number | null }));
        if (!facetValues || (partial[2] !== '=' && partial[2] !== '!=')) return [];
        return facetValues(partial[1]!)
            .filter((v) => matches(v.value))
            .slice(0, 8)
            .map((v) => ({ value: v.value, count: v.count as number | null }));
    }, [partial?.[1], partial?.[2], partial?.[3], facetValues, fieldByKey]); // eslint-disable-line react-hooks/exhaustive-deps

    // Every key that applies, fields first, then yours, then the built-ins —
    // the whole list, not a first few: the one you want is rarely in the top 8.
    const suggestions = useMemo<Suggestion[]>(() => {
        const typed = draft.trim().toLowerCase();
        if (/[=!<>~]/.test(typed)) return [];
        const hit = (key: string, label: string) => typed === '' || key.toLowerCase().includes(typed) || label.toLowerCase().includes(typed);
        const keys = dimensions
            .filter((d) => !signal || d.signals.length === 0 || d.signals.includes(signal))
            .filter((d) => !fieldByKey.has(d.key) && hit(d.key, d.label))
            .sort((a, b) => Number(a.builtin) - Number(b.builtin))
            .map((d) => ({ key: d.key, label: d.label, hint: d.key, op: '=', custom: !d.builtin }));
        return [...fields.filter((f) => hit(f.key, f.label)).map((f) => ({ key: f.key, label: f.label, hint: f.hint, op: f.op, custom: false })), ...keys];
    }, [dimensions, draft, fields, fieldByKey, signal]);

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
            else if (suggestions[active]) setDraft(`${suggestions[active]!.key}${suggestions[active]!.op}`);
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

    // Edit in place: the filter keeps its position in the query.
    const replace = (raw: string, next: Filter) => {
        const formatted = formatFilter(next);
        onChange(where.map((w) => (w === raw ? formatted : w)).filter((w, i, all) => all.indexOf(w) === i));
        setEditing(null);
    };

    const valuesFor = (key: string, op: Op): string[] => {
        const presets = fieldByKey.get(key)?.presets;
        if (presets) return presets;
        if (op !== '=' && op !== '!=') return [];
        return (facetValues?.(key) ?? []).map((v) => v.value);
    };

    return (
        <div className="t-qbuild">
            <span className="t-qbuild-lead mono">where</span>
            {where.map((raw) => {
                const f = parseFilter(raw);
                if (!f) return null;
                const dim = byKey.get(f.key);
                const label = dim?.label ?? fieldByKey.get(f.key)?.label ?? f.key;
                const neg = f.op === '!=' || f.op === '!~';
                return (
                    <span key={raw} className="t-chip-wrap">
                        <span className={`t-chip ${dim && !dim.builtin ? 'is-custom' : ''} ${neg ? 'is-neg' : ''} ${editing === raw ? 'is-editing' : ''}`} title={raw}>
                            <button type="button" className="t-chip-edit" onClick={() => setEditing(editing === raw ? null : raw)} aria-label={`Edit ${raw}`} aria-expanded={editing === raw}>
                                <span className="k">{label}</span>
                                <span className="op">{f.op}</span>
                                <b>{f.value === '' ? '∅' : f.op === '=' || f.op === '!=' ? <ValueText dimKey={f.key} value={f.value} /> : f.value}</b>
                            </button>
                            <button type="button" className="x" onClick={() => onChange(withoutFilter(where, raw))} aria-label={`Remove ${raw}`}><Icon name="x" size={11} /></button>
                        </span>
                        {editing === raw && (
                            <ChipEditor
                                filter={f}
                                label={label}
                                ops={opsFor(f)}
                                values={valuesFor}
                                onApply={(next) => replace(raw, next)}
                                onRemove={() => { onChange(withoutFilter(where, raw)); setEditing(null); }}
                                onClose={() => setEditing(null)}
                            />
                        )}
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
                        placeholder={fields[0] ? `key=value · ${fields[0].hint}` : 'key=value'}
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
                                        {v.count !== null && <code>{count(v.count)}</code>}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {valueSuggestions.length === 0 && suggestions.length > 0 && (
                        <ul className="t-suggest t-suggest-keys">
                            {suggestions.map((d, i) => (
                                <li key={d.key}>
                                    <button type="button" ref={i === active ? keepInView : undefined} className={i === active ? 'is-active' : ''} onMouseDown={(e) => { e.preventDefault(); setDraft(`${d.key}${d.op}`); setActive(0); input.current?.focus(); }}>
                                        <span className={d.custom ? 't-violet' : ''}>{d.label}</span>
                                        <code>{d.hint}</code>
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

/** The arrow-key row stays visible as the key list scrolls. */
function keepInView(el: HTMLButtonElement | null): void {
    el?.scrollIntoView?.({ block: 'nearest' });
}

const EQUALITY: Op[] = ['=', '!=', '=~', '!~'];
const COMPARISON: Op[] = ['>', '>=', '<', '<='];

/**
 * The operators that mean something for this filter: comparisons for
 * duration, = / != for the tokens the backend only matches exactly (status,
 * kind), and the full set when the value is a number (status code 500).
 */
function opsFor(f: Filter): Op[] {
    if (f.key === 'duration') return COMPARISON;
    if (f.key === 'status' || f.key === 'kind') return ['=', '!='];
    const numeric = /^-?\d+(\.\d+)?$/.test(f.value) || COMPARISON.includes(f.op);
    return numeric ? [...EQUALITY, ...COMPARISON] : EQUALITY;
}

/**
 * Edit an applied filter without removing and retyping it: pick the operator,
 * change the value (with the same value suggestions as adding), Enter applies,
 * Esc or a click outside cancels.
 */
function ChipEditor({ filter, label, ops, values, onApply, onRemove, onClose }: {
    filter: Filter;
    label: string;
    ops: Op[];
    values: (key: string, op: Op) => string[];
    onApply: (next: Filter) => void;
    onRemove: () => void;
    onClose: () => void;
}) {
    const [op, setOp] = useState<Op>(filter.op);
    const [value, setValue] = useState(filter.value);
    // Until you type, the suggestions are every option, not ones like the
    // current value.
    const [typedValue, setTypedValue] = useState(false);
    const root = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        input.current?.focus();
        input.current?.select();
        const onDoc = (e: MouseEvent) => {
            // The chip's own button toggles; anything else outside cancels.
            const target = e.target as Node;
            if (root.current && !root.current.contains(target) && !root.current.parentElement?.contains(target)) onClose();
        };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, [onClose]);

    const typed = typedValue ? value.trim().toLowerCase() : '';
    const suggestions = values(filter.key, op).filter((v) => v !== value && (typed === '' || v.toLowerCase().includes(typed))).slice(0, 8);
    const apply = (next: string = value) => onApply({ key: filter.key, op, value: next.trim() });

    return (
        <div
            className="t-chip-editor"
            ref={root}
            role="dialog"
            aria-label={`Edit ${label} filter`}
            onKeyDown={(e) => { if (e.key === 'Escape') { e.stopPropagation(); onClose(); } }}
        >
            <div className="t-chip-editor-head">
                <span className="t-eyebrow">{label}</span>
                <code>{filter.key}</code>
            </div>
            <div className="t-seg t-chip-editor-ops" role="radiogroup" aria-label="Operator">
                {ops.map((o) => (
                    <button key={o} type="button" role="radio" aria-checked={o === op} className={o === op ? 'is-on' : ''} onClick={() => { setOp(o); input.current?.focus(); input.current?.select(); }}>{o}</button>
                ))}
            </div>
            <form onSubmit={(e) => { e.preventDefault(); apply(); }}>
                <input ref={input} className="t-input t-input-sm mono" value={value} onChange={(e) => { setValue(e.target.value); setTypedValue(true); }} aria-label="Value" placeholder={op === '=~' || op === '!~' ? 'regex' : 'value'} />
            </form>
            {suggestions.length > 0 && (
                <ul className="t-chip-editor-values">
                    {suggestions.map((v) => (
                        <li key={v}><button type="button" onClick={() => apply(v)}><ValueText dimKey={filter.key} value={v} /></button></li>
                    ))}
                </ul>
            )}
            <div className="t-chip-editor-foot">
                <button type="button" className="t-btn t-btn-sm t-btn-ghost" onClick={onRemove}>Remove</button>
                <button type="button" className="t-btn t-btn-sm t-btn-primary" onClick={() => apply()}>Apply</button>
            </div>
        </div>
    );
}
