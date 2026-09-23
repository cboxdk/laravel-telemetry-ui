import { api } from '../api/client';

/**
 * Batches display-name lookups (`TelemetryUi::resolve()`): every id rendered in
 * the same tick joins one `GET dimensions/labels` per dimension, so a table of
 * 200 user ids costs one request, not 200. Unknown ids resolve to null; a
 * failed request resolves everything to null (the raw id still renders).
 */
const BATCH = 200;
const WAIT_MS = 15;

interface Pending {
    waiters: Map<string, ((label: string | null) => void)[]>;
    timer: ReturnType<typeof setTimeout>;
}

const pending = new Map<string, Pending>();

export function loadLabel(key: string, value: string): Promise<string | null> {
    return new Promise((resolve) => {
        let batch = pending.get(key);
        if (!batch) {
            batch = { waiters: new Map(), timer: setTimeout(() => void flush(key), WAIT_MS) };
            pending.set(key, batch);
        }
        batch.waiters.set(value, [...(batch.waiters.get(value) ?? []), resolve]);
    });
}

async function flush(key: string): Promise<void> {
    const batch = pending.get(key);
    pending.delete(key);
    if (!batch) return;

    const values = [...batch.waiters.keys()];
    for (let i = 0; i < values.length; i += BATCH) {
        const chunk = values.slice(i, i + BATCH);
        let labels: Record<string, string> = {};
        try {
            labels = (await api.get<{ labels: Record<string, string> }>('dimensions/labels', { key, values: chunk })).labels ?? {};
        } catch {
            /* fail open: ids render as ids */
        }
        for (const value of chunk) for (const done of batch.waiters.get(value) ?? []) done(labels[value] ?? null);
    }
}
