// URL = state. Every shareable bit of view state lives in the query string:
// the scope (period/from/to/service/env), the Explore query (where[], q,
// groupBy, facets[]), and the open drawer stack. Arrays use PHP-style
// `key[]=` so the same string round-trips to the API untouched.

export type SearchValue = string | string[];
export type Search = Record<string, SearchValue | undefined>;

export const SCOPE_KEYS = ['period', 'from', 'to', 'service', 'env'] as const;
export type ScopeKey = (typeof SCOPE_KEYS)[number];
export type Scope = Partial<Record<ScopeKey, string>>;

export function parseSearch(input: string): Search {
    const params = new URLSearchParams(input.startsWith('?') ? input.slice(1) : input);
    const out: Search = {};

    for (const [rawKey, value] of params) {
        if (rawKey.endsWith('[]')) {
            const key = rawKey.slice(0, -2);
            const current = out[key];
            out[key] = Array.isArray(current) ? [...current, value] : [value];
        } else {
            out[rawKey] = value;
        }
    }

    return out;
}

export function stringifySearch(search: Record<string, unknown>): string {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(search)) {
        if (value === undefined || value === null) continue;
        if (Array.isArray(value)) {
            for (const item of value) params.append(`${key}[]`, String(item));
        } else if (value !== '' || (SCOPE_KEYS as readonly string[]).includes(key)) {
            params.append(key, String(value));
        }
    }

    const s = params.toString().replace(/%5B%5D/g, '[]');
    return s === '' ? '' : `?${s}`;
}

export function str(search: Search, key: string): string {
    const v = search[key];
    return typeof v === 'string' ? v : '';
}

export function list(search: Search, key: string): string[] {
    const v = search[key];
    return Array.isArray(v) ? v : typeof v === 'string' && v !== '' ? [v] : [];
}

/** The scope subset of a search — what every link must carry forward. */
export function scopeOf(search: Search): Scope {
    const scope: Scope = {};
    for (const key of SCOPE_KEYS) {
        const v = search[key];
        if (typeof v === 'string') scope[key] = v;
    }
    return scope;
}

// ---- filters ------------------------------------------------------------------

export const OPS = ['!=', '=~', '!~', '>=', '<=', '=', '>', '<'] as const;
export type Op = (typeof OPS)[number];

export interface Filter {
    key: string;
    op: Op;
    value: string;
}

const FILTER_RE = /^([^=!<>~\s]+)\s*(!=|=~|!~|>=|<=|=|>|<)([\s\S]*)$/;

export function parseFilter(raw: string): Filter | null {
    const m = FILTER_RE.exec(raw.trim());
    if (!m) return null;
    return { key: m[1]!, op: m[2] as Op, value: m[3] ?? '' };
}

export function formatFilter(f: Filter): string {
    return `${f.key}${f.op}${f.value}`;
}

export function negate(op: Op): Op {
    switch (op) {
        case '=': return '!=';
        case '!=': return '=';
        case '=~': return '!~';
        case '!~': return '=~';
        default: return op;
    }
}

/** Add a filter, replacing any existing filter on the same key+value. */
export function withFilter(where: string[], filter: Filter): string[] {
    const next = where.filter((raw) => {
        const f = parseFilter(raw);
        return !(f && f.key === filter.key && (f.value === filter.value || (f.op === filter.op && f.op !== '=' && f.op !== '!=')));
    });
    return [...next, formatFilter(filter)];
}

export function withoutFilter(where: string[], raw: string): string[] {
    return where.filter((w) => w !== raw);
}

export function toggleFilter(where: string[], filter: Filter): string[] {
    const raw = formatFilter(filter);
    return where.includes(raw) ? withoutFilter(where, raw) : withFilter(where, filter);
}

// ---- drawer stack ---------------------------------------------------------------

export type DrawerEntry = { type: 'trace' | 'error' | 'issue'; id: string };

/** `drawer=trace:abc~error:0f3a…` — the stack, bottom first, top last. */
export function parseDrawer(raw: string): DrawerEntry[] {
    if (raw === '') return [];
    return raw.split('~').flatMap((part) => {
        const i = part.indexOf(':');
        const type = part.slice(0, i);
        const id = part.slice(i + 1);
        return (type === 'trace' || type === 'error' || type === 'issue') && id !== '' ? [{ type, id } as DrawerEntry] : [];
    });
}

export function formatDrawer(stack: DrawerEntry[]): string | undefined {
    return stack.length === 0 ? undefined : stack.map((e) => `${e.type}:${e.id}`).join('~');
}

export function pushDrawer(stack: DrawerEntry[], entry: DrawerEntry, replace = false): DrawerEntry[] {
    if (replace) return [entry];
    const top = stack[stack.length - 1];
    if (top && top.type === entry.type && top.id === entry.id) return stack;
    return [...stack, entry].slice(-8);
}
