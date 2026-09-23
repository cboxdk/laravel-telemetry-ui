import { useRouter, useRouterState } from '@tanstack/react-router';
import { useEffect, useState } from 'react';
import { matchingView, onViewsChanged, removeView, savedViews, saveView, type SavedView } from '../lib/views';
import { Icon } from './Icon';
import { Popover } from './Popover';

/** Re-render when views change anywhere (the palette saves them too). */
export function useSavedViews(): SavedView[] {
    const [views, setViews] = useState(savedViews);
    useEffect(() => onViewsChanged(() => setViews(savedViews())), []);
    return views;
}

/**
 * Name this exact view (page + filters + window) and get it back later — from
 * here or from ⌘K. Stored per browser.
 */
export function SavedViewsButton() {
    const router = useRouter();
    const location = useRouterState({ select: (s) => s.location });
    const views = useSavedViews();
    const [name, setName] = useState('');

    const here = `${location.searchStr ?? ''}`;
    const saved = matchingView(location.pathname, here);

    return (
        <Popover
            align="right"
            trigger={(toggle, open) => (
                <button type="button" className={`t-btn t-btn-sm ${saved ? 't-btn-secondary' : 't-btn-ghost'} ${open ? 'is-on' : ''}`} onClick={toggle} title="Saved views">
                    <Icon name={saved ? 'pin' : 'plus'} size={12} />
                    {saved ? saved.name : 'Save view'}
                </button>
            )}
        >
            {(close) => (
                <div className="t-views">
                    <form
                        className="t-views-save"
                        onSubmit={(e) => {
                            e.preventDefault();
                            saveView({ name: name || defaultName(location.pathname, here), pathname: location.pathname, search: here });
                            setName('');
                            close();
                        }}
                    >
                        <input className="t-input t-input-sm" value={name} onChange={(e) => setName(e.target.value)} placeholder={defaultName(location.pathname, here)} aria-label="View name" autoFocus />
                        <button type="submit" className="t-btn t-btn-sm t-btn-primary">{saved ? 'Update' : 'Save'}</button>
                    </form>
                    {views.length > 0 && (
                        <ul className="t-views-list">
                            {views.map((v) => (
                                <li key={v.name}>
                                    <button
                                        type="button"
                                        onClick={() => { close(); void router.navigate({ to: v.pathname, search: Object.fromEntries(new URLSearchParams(v.search)) as never }); }}
                                    >
                                        <Icon name="compass" size={12} />
                                        <span className="t-ellipsis">{v.name}</span>
                                        <code className="t-ellipsis">{v.pathname.replace('/explore/', '')}</code>
                                    </button>
                                    <button type="button" className="t-views-x" onClick={() => removeView(v.name)} aria-label={`Forget ${v.name}`}><Icon name="x" size={11} /></button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {views.length === 0 && <p className="t-views-empty">No saved views yet. Name this one and it shows up here and in ⌘K.</p>}
                </div>
            )}
        </Popover>
    );
}

/** "Requests · 2 filters" — a name you can accept without typing. */
function defaultName(pathname: string, search: string): string {
    const page = pathname.replace(/^\//, '').replace('explore/', '');
    const filters = new URLSearchParams(search).getAll('where[]').length;
    const label = page.charAt(0).toUpperCase() + page.slice(1);
    return filters > 0 ? `${label} · ${filters} filter${filters === 1 ? '' : 's'}` : label;
}
