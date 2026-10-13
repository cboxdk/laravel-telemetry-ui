import { useEffect, useRef, useState } from 'react';
import type { Annotation } from '../../api/types';
import { Go } from '../../lib/links';
import { dateTime } from '../../lib/format';

/** Markers closer than this share one flag (a rollout, a deploy + its purge). */
const GROUP_PX = 12;

export interface MarkGroup {
    x: number;
    items: Annotation[];
}

/** Annotations at their pixel x, grouped where they would overlap. */
export function groupMarks(points: { x: number; a: Annotation }[]): MarkGroup[] {
    const groups: MarkGroup[] = [];
    for (const { x, a } of [...points].sort((p, q) => p.x - q.x)) {
        const last = groups.at(-1);
        if (last && x - last.x < GROUP_PX) last.items.push(a);
        else groups.push({ x, items: [a] });
    }
    return groups;
}

/**
 * The flags along a chart's top edge, one per annotation line: hover (or
 * focus) says what the dashed line is — a deploy, a cache purge, an
 * incident — with its notes, hosts and a way to the trace and the errors
 * after it. A click pins the card open.
 */
export function AnnotationMarks({ groups, width }: { groups: MarkGroup[]; width: number }) {
    const [open, setOpen] = useState<{ x: number; pinned: boolean } | null>(null);
    const root = useRef<HTMLDivElement>(null);
    const leave = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        if (!open?.pinned) return;
        const onDoc = (e: MouseEvent) => { if (root.current && !root.current.contains(e.target as Node)) setOpen(null); };
        const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(null); };
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDoc);
            document.removeEventListener('keydown', onKey);
        };
    }, [open?.pinned]);

    useEffect(() => () => { if (leave.current) clearTimeout(leave.current); }, []);

    if (groups.length === 0) return null;

    const show = (x: number) => {
        if (leave.current) clearTimeout(leave.current);
        setOpen((o) => (o?.pinned ? o : { x, pinned: false }));
    };
    const hide = () => {
        if (leave.current) clearTimeout(leave.current);
        // A beat, so the pointer can travel from the flag into the card.
        leave.current = setTimeout(() => setOpen((o) => (o?.pinned ? o : null)), 160);
    };

    return (
        <div className="t-annots" ref={root}>
            {groups.map((g) => {
                const first = g.items[0]!;
                const isOpen = open?.x === g.x;
                const label = g.items.length === 1 ? first.label : `${g.items.length} events`;
                return (
                    <div key={`${g.x}:${first.xAxis}`} className="t-annot" style={{ left: g.x }} onMouseEnter={() => show(g.x)} onMouseLeave={hide}>
                        <button
                            type="button"
                            className={`t-annot-flag ${isOpen ? 'is-open' : ''}`}
                            style={{ ['--annot' as string]: first.color ?? 'var(--violet)' }}
                            aria-label={label}
                            aria-expanded={isOpen}
                            onFocus={() => show(g.x)}
                            onBlur={hide}
                            onClick={(e) => { e.stopPropagation(); setOpen((o) => (o?.x === g.x && o.pinned ? null : { x: g.x, pinned: true })); }}
                        >
                            {g.items.length > 1 ? g.items.length : ''}
                        </button>
                        {isOpen && (
                            <div className={`t-annot-card ${g.x > width / 2 ? 'is-left' : ''}`} role="dialog" aria-label={label}>
                                {g.items.map((a, i) => <AnnotationDetail key={i} a={a} />)}
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function AnnotationDetail({ a }: { a: Annotation }) {
    const at = Math.floor(a.xAxis / 1000);
    const hosts = a.hosts ?? [];
    return (
        <div className="t-annot-item">
            <div className="t-annot-title">
                <i style={{ background: a.color ?? 'var(--violet)' }} />
                <strong>{a.label}</strong>
            </div>
            <div className="t-annot-meta mono">
                {a.kind} · {a.time || dateTime(a.xAxis)}{a.timeEnd ? ` → ${a.timeEnd}` : ''}
            </div>
            {a.notes && <p className="t-annot-notes">{a.notes}</p>}
            {(a.count ?? 1) > 1 && (
                <div className="t-annot-meta">
                    ×{a.count}{hosts.length > 0 ? ` from ${a.hostCount ?? hosts.length} host${(a.hostCount ?? hosts.length) === 1 ? '' : 's'}: ${hosts.join(', ')}${(a.hostCount ?? 0) > hosts.length ? '…' : ''}` : ''}
                </div>
            )}
            {(a.count ?? 1) <= 1 && hosts.length > 0 && <div className="t-annot-meta">{hosts.join(', ')}</div>}
            <div className="t-annot-links">
                {a.traceId && <Go link={{ to: 'trace', id: a.traceId, at: a.xAxis }}>Trace</Go>}
                <Go link={{ to: 'explore', signal: 'errors', where: [], params: { from: String(at - 15 * 60), to: String(at + 60 * 60) } }}>Errors after →</Go>
            </div>
        </div>
    );
}
