import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { api } from '../../api/client';
import type { CellAction } from '../../api/types';
import { Icon } from '../Icon';
import { Popover } from '../Popover';

/**
 * What a viewer can do to one row, from the row.
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
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const run = async (action: CellAction, close: () => void) => {
        if (action.confirm && !window.confirm(action.confirm)) return;

        setBusy(true);
        setError(null);

        try {
            await api.post(action.endpoint, action.body ?? {});
            close();
            await queryClient.invalidateQueries();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'That did not work.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popover
            align="right"
            trigger={(toggle, open) => (
                <button
                    type="button"
                    className={`t-cell-actions-trigger ${open ? 'is-open' : ''}`}
                    onClick={(e) => { e.stopPropagation(); toggle(); }}
                    aria-label="Actions"
                    aria-haspopup="menu"
                >
                    <Icon name="more" size={14} />
                </button>
            )}
        >
            {(close) => (
                <div className="t-menu" onClick={(e) => e.stopPropagation()}>
                    {actions.map((action) => (
                        <button
                            key={action.label + action.endpoint}
                            type="button"
                            disabled={busy}
                            className={action.tone === 'danger' ? 't-menu-danger' : undefined}
                            onClick={() => void run(action, close)}
                        >
                            {action.label}
                        </button>
                    ))}
                    {error !== null && <div className="t-menu-foot t-tone-danger">{error}</div>}
                </div>
            )}
        </Popover>
    );
}
