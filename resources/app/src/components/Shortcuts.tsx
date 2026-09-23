import { useEffect, useState } from 'react';
import { useScope, useSetSearch } from '../lib/state';
import { Icon } from './Icon';

/** "15m" / "2h" / "7d" → seconds. */
export function periodSeconds(period: string): number | null {
    const m = /^(\d+)(m|h|d)$/.exec(period.trim());
    if (!m) return null;
    return Number(m[1]) * (m[2] === 'm' ? 60 : m[2] === 'h' ? 3600 : 86400);
}

/**
 * Move the window one window's length back or forward, keeping its length.
 * Returns null when there is nothing to step (unknown period). Never steps
 * past now: the future is empty.
 */
export function stepWindow(scope: { period?: string; from?: string; to?: string }, direction: 1 | -1, now: number): { from: string; to: string } | null {
    const from = Number(scope.from);
    const to = Number(scope.to);
    const fixed = Boolean(scope.from && scope.to) && to > from;
    const length = fixed ? to - from : periodSeconds(scope.period ?? '') ?? 0;
    if (length <= 0) return null;

    const end = Math.min((fixed ? to : now) + direction * length, now);
    return { from: String(end - length), to: String(end) };
}

const KEYS: { keys: string; what: string }[] = [
    { keys: '⌘K', what: 'Command palette — pages, filters, search, a pasted trace id' },
    { keys: '/', what: 'Focus the search box on this page' },
    { keys: '[  ]', what: 'Step the time window back / forward' },
    { keys: 'n', what: 'Jump back to now (live window)' },
    { keys: 'r', what: 'Refresh every query on the page' },
    { keys: '⌘.', what: 'Collapse or expand the section sidebar' },
    { keys: 'j  k', what: 'Move between result rows — with a trace open, step through them (↑ ↓ too)' },
    { keys: '⏎', what: 'Open the focused row' },
    { keys: 'Esc', what: 'Close the top drawer or menu' },
    { keys: '?', what: 'This list' },
];

/**
 * Global keys that aren't tied to one screen, plus the sheet that documents
 * them. Time stepping keeps the window length and moves it: the same view,
 * one window earlier.
 */
export function Shortcuts({ onPalette }: { onPalette: () => void }) {
    const [open, setOpen] = useState(false);
    const scope = useScope();
    const set = useSetSearch();

    useEffect(() => {
        const onOpen = () => setOpen(true);
        window.addEventListener('telemetry-ui:shortcuts', onOpen);

        const onKey = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement | null;
            const typing = typeof target?.closest === 'function' && target.closest('input,textarea,select,[contenteditable]') !== null;
            if (e.metaKey || e.ctrlKey || e.altKey || typing) return;

            if (e.key === '?') { e.preventDefault(); setOpen((o) => !o); return; }
            if (e.key === 'Escape' && open) { e.preventDefault(); setOpen(false); return; }
            if (e.key === '/') {
                e.preventDefault();
                const box = document.querySelector<HTMLInputElement>('.t-qbuild-search input, .t-search-control input, .t-page-head input');
                if (box) box.focus();
                else onPalette();
                return;
            }
            if (e.key === 'r') { e.preventDefault(); window.dispatchEvent(new CustomEvent('telemetry-ui:refresh')); return; }

            if (e.key === '[' || e.key === ']') {
                const stepped = stepWindow(scope, e.key === ']' ? 1 : -1, Math.floor(Date.now() / 1000));
                if (!stepped) return;
                e.preventDefault();
                set(stepped);
                return;
            }
            if (e.key === 'n' && scope.from) { e.preventDefault(); set({ from: undefined, to: undefined }); }
        };

        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('telemetry-ui:shortcuts', onOpen);
        };
    }, [open, onPalette, scope, set]);

    if (!open) return null;

    return (
        <div className="t-modal-backdrop is-top" role="presentation" onMouseDown={(e) => e.target === e.currentTarget && setOpen(false)}>
            <div className="t-modal t-shortcuts" role="dialog" aria-label="Keyboard shortcuts">
                <header className="t-modal-head">
                    <h2>Keyboard</h2>
                    <button type="button" className="t-iconbtn" onClick={() => setOpen(false)} aria-label="Close"><Icon name="x" size={15} /></button>
                </header>
                <dl className="t-shortcut-list">
                    {KEYS.map((k) => (
                        <div key={k.keys}>
                            <dt>{k.keys.split('  ').map((key) => <kbd key={key}>{key}</kbd>)}</dt>
                            <dd>{k.what}</dd>
                        </div>
                    ))}
                </dl>
            </div>
        </div>
    );
}
