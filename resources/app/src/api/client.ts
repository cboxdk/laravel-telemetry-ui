import { boot } from '../boot';
import { timeZone } from '../lib/time';

export type ApiErrorType = 'backend' | 'forbidden' | 'invalid' | 'not_found' | 'network' | 'unknown';

/**
 * A typed API failure — the SPA renders backend-down, forbidden, not-found
 * and bad-request differently instead of a blank panel.
 */
export class ApiError extends Error {
    constructor(
        public readonly type: ApiErrorType,
        message: string,
        public readonly status: number = 0,
        /** For a `backend` error, what went wrong there: `timeout`, `dropped`, `unreachable`, … (BackendStatus). */
        public readonly backend?: string,
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

/** Backend failures that can pass on their own: the connection, not the query. */
const TRANSIENT = new Set(['unreachable', 'dropped']);

/**
 * Whether a failed read is worth sending again. A blip in the network or a
 * connection the backend dropped can pass by itself. A timeout cannot: the
 * same query takes as long again, so a retry only doubles the wait before the
 * error shows and the load on a backend that is already struggling. Nor can
 * an answer (forbidden, not found, invalid) or a backend's refusal.
 */
export function shouldRetry(failures: number, error: unknown, max = 1): boolean {
    if (failures >= max) return false;
    if (!(error instanceof ApiError)) return true;
    if (error.type === 'network' || error.type === 'unknown') return true;
    // A server older than this field says nothing: keep retrying it as before.
    return error.type === 'backend' && (error.backend === undefined || TRANSIENT.has(error.backend));
}

export type Params = Record<string, string | number | boolean | null | undefined | string[]>;

/**
 * Query string with PHP-style arrays (`where[]=a&where[]=b`) — what Laravel
 * reads as a list. Empty scope params are kept on purpose (`service=` means
 * "all services", not "use the remembered one").
 */
export function toQuery(params: Params): string {
    const q = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (value === undefined || value === null) continue;
        if (Array.isArray(value)) {
            for (const item of value) q.append(`${key}[]`, item);
        } else {
            q.append(key, String(value));
        }
    }

    const s = q.toString();
    return s === '' ? '' : `?${s}`;
}

export function apiUrl(path: string, params: Params = {}): string {
    return `${boot().api}/${path.replace(/^\//, '')}${toQuery(params)}`;
}

async function parse(response: Response): Promise<unknown> {
    const text = await response.text();
    try {
        return text === '' ? null : JSON.parse(text);
    } catch {
        return text;
    }
}

export async function request<T>(method: 'GET' | 'POST' | 'DELETE', path: string, params: Params = {}, body?: unknown, signal?: AbortSignal): Promise<T> {
    let response: Response;

    try {
        response = await fetch(apiUrl(path, method === 'GET' ? params : {}), {
            method,
            signal,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                // The server formats its clocks in the zone the viewer reads.
                'X-Timezone': timeZone(),
                ...(method === 'POST' ? { 'Content-Type': 'application/json' } : {}),
                ...(method !== 'GET' ? { 'X-CSRF-TOKEN': boot().csrf } : {}),
            },
            body: method === 'POST' ? JSON.stringify(body ?? {}) : undefined,
        });
    } catch (e) {
        if (e instanceof DOMException && e.name === 'AbortError') throw e;
        throw new ApiError('network', 'Could not reach the dashboard API.');
    }

    const data = await parse(response);

    if (!response.ok) {
        const err = (data as { error?: { type?: ApiErrorType; message?: string; backend?: string } } | null)?.error;
        const type: ApiErrorType = err?.type ?? (response.status === 403 ? 'forbidden' : response.status === 404 ? 'not_found' : response.status === 419 ? 'forbidden' : 'unknown');
        throw new ApiError(type, err?.message ?? `Request failed (${response.status}).`, response.status, err?.backend);
    }

    return data as T;
}

export const api = {
    get: <T>(path: string, params?: Params, signal?: AbortSignal) => request<T>('GET', path, params, undefined, signal),
    post: <T>(path: string, body?: unknown) => request<T>('POST', path, {}, body),
    delete: <T>(path: string) => request<T>('DELETE', path),
};
