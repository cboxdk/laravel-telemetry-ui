import { load, save } from './storage';

export interface SavedView {
    name: string;
    pathname: string;
    /** The query string, including the filters and the window. */
    search: string;
    at: number;
}

const KEY = 'views';
const MAX = 40;

/** Views this viewer saved, newest first. Per browser — a convenience, not shared state. */
export function savedViews(): SavedView[] {
    const views = load<SavedView[]>(KEY, []);
    return Array.isArray(views) ? views.filter((v) => typeof v?.name === 'string' && typeof v?.pathname === 'string') : [];
}

export function saveView(view: Omit<SavedView, 'at'>): SavedView[] {
    const name = view.name.trim().slice(0, 60);
    if (name === '') return savedViews();
    // Re-saving a name replaces it: the name is the identity.
    const next = [{ ...view, name, at: Date.now() }, ...savedViews().filter((v) => v.name !== name)].slice(0, MAX);
    save(KEY, next);
    notify();
    return next;
}

export function removeView(name: string): SavedView[] {
    const next = savedViews().filter((v) => v.name !== name);
    save(KEY, next);
    notify();
    return next;
}

/** Same page, same query — so "Save view" can show as already saved. */
export function matchingView(pathname: string, search: string): SavedView | undefined {
    return savedViews().find((v) => v.pathname === pathname && v.search === search);
}

const EVENT = 'telemetry-ui:views';

function notify(): void {
    window.dispatchEvent(new CustomEvent(EVENT));
}

export function onViewsChanged(listener: () => void): () => void {
    window.addEventListener(EVENT, listener);
    return () => window.removeEventListener(EVENT, listener);
}
