import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { Icon } from './Icon';

export interface Option {
    value: string;
    label: string;
    hint?: string;
}

/**
 * A searchable single-select (the design system forbids native <select>).
 * Keyboard: ↑/↓ to move, Enter to pick, Esc to close; typing filters.
 */
export function Combobox({ value, options, onChange, placeholder = 'Select…', label, disabled, className, mono, width }: {
    value: string;
    options: Option[];
    onChange: (value: string) => void;
    placeholder?: string;
    label?: string;
    disabled?: boolean;
    className?: string;
    mono?: boolean;
    width?: number;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const root = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLInputElement>(null);
    const listId = useId();

    const selected = options.find((o) => o.value === value);
    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        return q === '' ? options : options.filter((o) => o.label.toLowerCase().includes(q) || o.value.toLowerCase().includes(q));
    }, [options, query]);

    useEffect(() => {
        if (!open) return;
        const onDoc = (e: MouseEvent) => {
            if (root.current && !root.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, [open]);

    useEffect(() => {
        if (open) {
            setQuery('');
            setActive(Math.max(0, options.findIndex((o) => o.value === value)));
            setTimeout(() => input.current?.focus(), 0);
        }
    }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

    const pick = (o: Option | undefined) => {
        if (!o) return;
        onChange(o.value);
        setOpen(false);
    };

    const onKey = (e: KeyboardEvent) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(filtered.length - 1, a + 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(0, a - 1)); }
        else if (e.key === 'Enter') { e.preventDefault(); pick(filtered[active]); }
        else if (e.key === 'Escape') { e.preventDefault(); setOpen(false); }
    };

    return (
        <div className={`t-combo ${className ?? ''}`} ref={root} style={width ? { minWidth: width } : undefined}>
            <button
                type="button"
                className="t-combo-btn"
                disabled={disabled}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-label={label}
                onClick={() => setOpen((o) => !o)}
            >
                {label && <span className="t-combo-label">{label}</span>}
                <span className={`t-combo-value ${mono ? 'mono' : ''} ${selected ? '' : 'is-placeholder'}`}>{selected?.label ?? placeholder}</span>
                <Icon name="chevronDown" size={14} />
            </button>
            {open && (
                <div className="t-combo-pop" role="dialog">
                    {options.length > 6 && (
                        <div className="t-combo-search">
                            <Icon name="search" size={13} />
                            <input ref={input} value={query} onChange={(e) => { setQuery(e.target.value); setActive(0); }} onKeyDown={onKey} placeholder="Filter…" aria-controls={listId} />
                        </div>
                    )}
                    <ul className="t-combo-list" role="listbox" id={listId} tabIndex={-1} onKeyDown={onKey}>
                        {filtered.length === 0 && <li className="t-combo-empty">No matches</li>}
                        {filtered.map((o, i) => (
                            <li
                                key={o.value}
                                role="option"
                                aria-selected={o.value === value}
                                className={`t-combo-opt ${i === active ? 'is-active' : ''} ${o.value === value ? 'is-selected' : ''}`}
                                onMouseEnter={() => setActive(i)}
                                onMouseDown={(e) => { e.preventDefault(); pick(o); }}
                            >
                                <span className={mono ? 'mono' : undefined}>{o.label}</span>
                                {o.hint && <span className="t-combo-hint">{o.hint}</span>}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
