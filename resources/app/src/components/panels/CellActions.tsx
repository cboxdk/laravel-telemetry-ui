import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { api } from '../../api/client';
import type { CellAction } from '../../api/types';
import { Icon } from '../Icon';

/**
 * What a viewer can do to one row, from the row.
 *
 * The menu is portalled to the body and positioned against the trigger
 * rather than rendered in place: a table cell clips its content to
 * truncate long values, and a menu rendered inside one is invisible. It is
 * only visible in a browser — jsdom has no layout, so a unit test cannot
 * catch it.
 *
 * The endpoint is a path under this dashboard's own API, never a URL — a
 * panel payload is data, and data must not be able to make the browser post
 * somewhere else. Whether the viewer may actually do it is the endpoint's
 * decision; a refusal comes back as an error and is shown as one.
 *
 * Anything that writes can change what any other panel is showing, so a
 * success refetches the lot rather than guessing which.
 */
export function CellActions({ actions }: { actions: CellAction[] }) {
    const queryClient = useQueryClient();
    const trigger = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [at, setAt] = useState({ top: 0, left: 0 });

    useLayoutEffect(() => {
        if (!open || !trigger.current) return;

        const box = trigger.current.getBoundingClientRect();
        const width = 220;
        // Keep it on screen: flip above when it would run off the bottom,
        // and pull it left when it would run off the right.
        const below = window.innerHeight - box.bottom;
        const height = menu.current?.offsetHeight ?? 120;

        setAt({
            top: below < height + 12 ? Math.max(8, box.top - height - 6) : box.bottom + 6,
            left: Math.max(8, Math.min(box.right - width, window.innerWidth - width - 8)),
        });
    }, [open]);

    useEffect(() => {
        if (!open) return;

        const onDown = (e: MouseEvent) => {
            const target = e.target as Node;
            if (!menu.current?.contains(target) && !trigger.current?.contains(target)) setOpen(false);
        };
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
        // A menu pinned to a rect has to go when that rect moves.
        const onScroll = () => setOpen(false);

        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        window.addEventListener('scroll', onScroll, true);

        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
            window.removeEventListener('scroll', onScroll, true);
        };
    }, [open]);

    const run = async (action: CellAction) => {
        if (action.confirm && !window.confirm(action.confirm)) return;

        setBusy(true);
        setError(null);

        try {
            await api.post(action.endpoint, action.body ?? {});
            setOpen(false);
            await queryClient.invalidateQueries();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'That did not work.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <button
                ref={trigger}
                type="button"
                className={`t-cell-actions-trigger ${open ? 'is-open' : ''}`}
                onClick={(e) => { e.stopPropagation(); setError(null); setOpen((o) => !o); }}
                aria-label="Actions"
                aria-haspopup="menu"
                aria-expanded={open}
            >
                <Icon name="more" size={14} />
            </button>
            {open && createPortal(
                <div
                    ref={menu}
                    className="t-menu t-cell-actions-menu"
                    role="menu"
                    style={{ top: at.top, left: at.left }}
                    onClick={(e) => e.stopPropagation()}
                >
                    {actions.map((action) => (
                        <button
                            key={action.label + action.endpoint}
                            type="button"
                            role="menuitem"
                            disabled={busy}
                            className={action.tone === 'danger' ? 't-menu-danger' : undefined}
                            onClick={() => void run(action)}
                        >
                            {action.label}
                        </button>
                    ))}
                    {error !== null && <div className="t-menu-foot t-tone-danger">{error}</div>}
                </div>,
                document.body,
            )}
        </>
    );
}
