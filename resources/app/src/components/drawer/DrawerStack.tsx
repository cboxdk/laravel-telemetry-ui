import { Link } from '@tanstack/react-router';
import { useEffect } from 'react';
import type { Bootstrap } from '../../api/types';
import { formatDrawer, parseDrawer, scopeOf, str, type DrawerEntry } from '../../lib/search';
import { useSearchState, useSetSearch } from '../../lib/state';
import { Icon } from '../Icon';
import { ErrorGroupView } from './ErrorGroupView';
import { IssueView } from './IssueView';
import { TraceView } from './TraceView';

function label(e: DrawerEntry): string {
    return e.type === 'trace' ? `trace ${e.id.slice(0, 8)}` : e.type === 'error' ? `error ${e.id.slice(0, 8)}` : `#${e.id.replace(/^#/, '')}`;
}

/**
 * The stacked, deep-linkable drawer. The stack lives in the URL
 * (`drawer=trace:…~error:…`): opening a trace from an issue pushes, back pops,
 * a shared link reopens the exact stack, and the browser back button walks it.
 */
export function DrawerStack({ boot }: { boot: Bootstrap }) {
    const search = useSearchState();
    const set = useSetSearch();
    const stack = parseDrawer(str(search, 'drawer'));
    const top = stack.at(-1);

    useEffect(() => {
        if (!top) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && !(e.target as HTMLElement).closest('input,textarea,.t-modal,.t-palette')) set({ drawer: undefined });
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [top, set]);

    if (!top) return null;

    const truncate = (n: number) => set({ drawer: formatDrawer(stack.slice(0, n)) });
    const full = top.type === 'trace' ? `/traces/${top.id}` : top.type === 'error' ? `/errors/${top.id}` : null;

    return (
        <aside className="t-drawer" aria-label="Detail">
            <div className="t-drawer-bar">
                {stack.length > 1 && (
                    <button type="button" className="t-iconbtn" onClick={() => truncate(stack.length - 1)} title="Back"><Icon name="arrowLeft" size={14} /></button>
                )}
                <nav className="t-crumbs" aria-label="Drawer stack">
                    {stack.map((e, i) => (
                        <span key={`${e.type}:${e.id}:${i}`} className="t-crumb">
                            {i > 0 && <Icon name="chevronRight" size={11} />}
                            {i === stack.length - 1 ? <span className="mono">{label(e)}</span> : <button type="button" className="mono" onClick={() => truncate(i + 1)}>{label(e)}</button>}
                        </span>
                    ))}
                </nav>
                <span className="t-topbar-spacer" />
                {full && (
                    <Link to={full} search={scopeOf(search) as never} className="t-btn t-btn-sm t-btn-ghost" title="Open as a full page">
                        <Icon name="external" size={12} />Full page
                    </Link>
                )}
                <button type="button" className="t-iconbtn" onClick={() => set({ drawer: undefined })} title="Close (Esc)"><Icon name="x" size={15} /></button>
            </div>
            <div className="t-drawer-body" key={`${top.type}:${top.id}`}>
                {top.type === 'trace' && <TraceView traceId={top.id} />}
                {top.type === 'error' && <ErrorGroupView group={top.id} />}
                {top.type === 'issue' && (boot.capabilities.issues ? <IssueView id={top.id} /> : <div className="t-pad t-dim">No issue tracker configured.</div>)}
            </div>
        </aside>
    );
}
