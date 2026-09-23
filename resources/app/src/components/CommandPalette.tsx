import { useRouter } from '@tanstack/react-router';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { Bootstrap } from '../api/types';
import { useGo } from '../lib/links';
import { parseFilter, parseSearch, scopeOf } from '../lib/search';
import { useSetSearch } from '../lib/state';
import { useTheme } from '../lib/theme';
import { Icon } from './Icon';
import type { NavArea } from './shell/nav';
import { useSavedViews } from './SavedViews';

interface Command {
    id: string;
    group: string;
    label: string;
    hint?: string;
    icon: string;
    run: () => void;
}

/**
 * ⌘K: jump to any page or entity list, switch service/environment, open a
 * pasted trace id or error group, or run a `key=value` filter in Explore.
 */
export function CommandPalette({ boot, areas, open, onClose }: { boot: Bootstrap; areas: NavArea[]; open: boolean; onClose: () => void }) {
    const router = useRouter();
    const go = useGo();
    const set = useSetSearch();
    const [, toggleTheme] = useTheme();
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const input = useRef<HTMLInputElement>(null);
    const views = useSavedViews();

    useEffect(() => {
        if (open) {
            setQuery('');
            setActive(0);
            setTimeout(() => input.current?.focus(), 0);
        }
    }, [open]);

    const commands = useMemo<Command[]>(() => {
        const nav = (to: string, search: Record<string, unknown> = {}) => () => {
            const current = parseSearch(router.state.location.searchStr);
            void router.navigate({ to, search: { ...scopeOf(current), ...search } as never });
        };
        const q = query.trim();
        const dynamic: Command[] = [];

        if (/^[0-9a-f]{16,32}$/i.test(q)) {
            dynamic.push({ id: 'trace', group: 'Open', label: `Trace ${q}`, icon: 'trace', run: () => go({ to: 'trace', id: q.toLowerCase() }) });
        }
        if (/^[0-9a-f]{12}$/i.test(q)) {
            dynamic.push({ id: 'error', group: 'Open', label: `Error group ${q}`, icon: 'bug', run: () => go({ to: 'error', group: q.toLowerCase() }) });
        }
        const f = parseFilter(q);
        if (f) {
            for (const signal of ['requests', 'logs', 'traces'] as const) {
                dynamic.push({ id: `filter-${signal}`, group: 'Filter', label: `${q}`, hint: `in ${signal}`, icon: 'filter', run: nav(`/explore/${signal}`, { where: [q] }) });
            }
            const dim = boot.dimensions.find((d) => d.key === f.key);
            if (dim && f.op === '=' && f.value !== '') {
                dynamic.push({ id: 'entity', group: 'Open', label: `${dim.label} ${f.value}`, hint: 'entity page', icon: 'chevronRight', run: () => go({ to: 'entity', type: dim.entity, value: f.value }) });
            }
        }

        const viewCommands: Command[] = views.map((v) => ({
            id: `view-${v.name}`,
            group: 'Saved views',
            label: v.name,
            hint: v.pathname.replace('/explore/', ''),
            icon: 'pin',
            run: () => void router.navigate({ to: v.pathname, search: Object.fromEntries(new URLSearchParams(v.search)) as never }),
        }));

        const pages: Command[] = areas.flatMap((a) =>
            a.sections.flatMap((s) => s.items.map((i) => ({ id: `nav-${i.to}`, group: a.label, label: i.label, hint: s.title, icon: a.icon, run: nav(i.to, i.search) }))),
        );
        const scope: Command[] = [
            ...(boot.scope.servicesLocked ? [] : [{ id: 'svc-all', group: 'Service', label: 'All services', icon: 'server', run: () => set({ service: '' }) }]),
            ...boot.scope.services.map((s) => ({ id: `svc-${s}`, group: 'Service', label: s, icon: 'server', run: () => set({ service: s }) })),
            ...boot.scope.environments.map((e) => ({ id: `env-${e}`, group: 'Environment', label: e, icon: 'layers', run: () => set({ env: e }) })),
        ];
        const actions: Command[] = [
            { id: 'theme', group: 'Actions', label: 'Toggle light / dark theme', icon: 'moon', run: toggleTheme },
            ...boot.periods.map((p) => ({ id: `period-${p.value}`, group: 'Time window', label: `Last ${p.label}`, icon: 'clock', run: () => set({ period: p.value, from: undefined, to: undefined }) })),
        ];

        const all = [...viewCommands, ...pages, ...scope, ...actions];
        const needle = q.toLowerCase();
        const matched = needle === '' ? all : f || dynamic.length > 0 ? [] : all.filter((c) => `${c.label} ${c.group} ${c.hint ?? ''}`.toLowerCase().includes(needle));

        // Free text is never a dead end: search it across the signals.
        const search: Command[] = q === '' || f ? [] : [
            ...(q.startsWith('/') ? [{ id: 'route', group: 'Open', label: `Route ${q}`, hint: 'entity page', icon: 'chevronRight', run: () => go({ to: 'entity', type: 'route', value: q }) }] : []),
            { id: 'q-requests', group: 'Search', label: `“${q}”`, hint: 'in request and span names', icon: 'search', run: nav('/explore/requests', { q }) },
            { id: 'q-logs', group: 'Search', label: `“${q}”`, hint: 'in log lines', icon: 'search', run: nav('/explore/logs', { q }) },
            { id: 'q-errors', group: 'Search', label: `“${q}”`, hint: 'in exceptions', icon: 'search', run: nav('/explore/errors', { q }) },
        ];

        return [...dynamic, ...matched, ...search].slice(0, 60);
    }, [query, areas, boot, router, go, set, toggleTheme, views]);

    if (!open) return null;

    const run = (c: Command | undefined) => {
        if (!c) return;
        onClose();
        c.run();
    };

    let lastGroup = '';

    return (
        <div className="t-modal-backdrop is-top" role="presentation" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
            <div className="t-palette" role="dialog" aria-label="Command palette">
                <div className="t-palette-input">
                    <Icon name="search" size={15} />
                    <input
                        ref={input}
                        value={query}
                        placeholder="Jump to a page, search, paste a trace id, or type key=value…"
                        onChange={(e) => { setQuery(e.target.value); setActive(0); }}
                        onKeyDown={(e) => {
                            if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(commands.length - 1, a + 1)); }
                            else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(0, a - 1)); }
                            else if (e.key === 'Enter') { e.preventDefault(); run(commands[active]); }
                            else if (e.key === 'Escape') { e.preventDefault(); onClose(); }
                        }}
                        aria-label="Command"
                    />
                    <kbd>esc</kbd>
                </div>
                <ul className="t-palette-list" role="listbox">
                    {commands.length === 0 && <li className="t-palette-empty">No matches</li>}
                    {commands.map((c, i) => {
                        const header = c.group !== lastGroup ? c.group : null;
                        lastGroup = c.group;
                        return (
                            <li key={c.id} role="none">
                                {header && <div className="t-palette-group">{header}</div>}
                                <button type="button" role="option" aria-selected={i === active} className={i === active ? 'is-active' : ''} onMouseEnter={() => setActive(i)} onClick={() => run(c)}>
                                    <Icon name={c.icon} size={14} />
                                    <span className="mono-soft">{c.label}</span>
                                    {c.hint && <span className="t-palette-hint">{c.hint}</span>}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            </div>
        </div>
    );
}
