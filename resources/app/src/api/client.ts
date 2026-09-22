import { boot } from '../boot';

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
    ) {
        super(message);
        this.name = 'ApiError';
    }
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

export async function request<T>(method: 'GET' | 'POST', path: string, params: Params = {}, body?: unknown, signal?: AbortSignal): Promise<T> {
    let response: Response;

    try {
        response = await fetch(apiUrl(path, method === 'GET' ? params : {}), {
            method,
            signal,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(method === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': boot().csrf } : {}),
            },
            body: method === 'POST' ? JSON.stringify(body ?? {}) : undefined,
        });
    } catch (e) {
        if (e instanceof DOMException && e.name === 'AbortError') throw e;
        throw new ApiError('network', 'Could not reach the dashboard API.');
    }

    const data = await parse(response);

    if (!response.ok) {
        const err = (data as { error?: { type?: ApiErrorType; message?: string } } | null)?.error;
        const type: ApiErrorType = err?.type ?? (response.status === 403 ? 'forbidden' : response.status === 404 ? 'not_found' : response.status === 419 ? 'forbidden' : 'unknown');
        throw new ApiError(type, err?.message ?? `Request failed (${response.status}).`, response.status);
    }

    return data as T;
}

export const api = {
    get: <T>(path: string, params?: Params, signal?: AbortSignal) => request<T>('GET', path, params, undefined, signal),
    post: <T>(path: string, body?: unknown) => request<T>('POST', path, {}, body),
};
