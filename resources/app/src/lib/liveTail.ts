import { useEffect, useRef, useState } from 'react';
import { apiUrl, type Params } from '../api/client';

/** Parse `event: rows` batches out of an SSE body (polling fallback + tests). */
export function parseSse<T>(body: string): { rows: T[]; lastId: string | null } {
    const rows: T[] = [];
    let lastId: string | null = null;

    for (const block of body.split(/\n\n/)) {
        let event = 'message';
        let data = '';
        for (const line of block.split('\n')) {
            if (line.startsWith('event:')) event = line.slice(6).trim();
            else if (line.startsWith('data:')) data += line.slice(5).trim();
            else if (line.startsWith('id:')) lastId = line.slice(3).trim();
        }
        if (event === 'rows' && data !== '') {
            try {
                rows.push(...((JSON.parse(data) as { rows: T[] }).rows ?? []));
            } catch {
                /* malformed batch */
            }
        }
    }

    return { rows, lastId };
}

/**
 * Live tail over SSE (`/api/v2/stream/{signal}`): new rows are prepended as
 * they arrive. EventSource reconnects by itself and resumes from the last
 * event id; if SSE is unavailable (a proxy buffering it, an old browser) it
 * falls back to polling the same endpoint with `once=1`.
 */
export function useLiveTail<T>(signal: 'logs' | 'requests', params: Params, enabled: boolean, sinceNano: string | null, max = 2000) {
    const [rows, setRows] = useState<T[]>([]);
    const [mode, setMode] = useState<'off' | 'sse' | 'poll'>('off');
    const cursor = useRef<string | null>(sinceNano);
    const key = JSON.stringify(params);

    useEffect(() => {
        setRows([]);
        cursor.current = sinceNano;
    }, [key, enabled]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (!enabled) {
            setMode('off');
            return;
        }

        const push = (batch: T[]) => {
            if (batch.length === 0) return;
            setRows((prev) => [...batch, ...prev].slice(0, max));
        };

        let poll: ReturnType<typeof setInterval> | null = null;
        const startPolling = () => {
            setMode('poll');
            const tick = async () => {
                try {
                    const res = await fetch(apiUrl(`stream/${signal}`, { ...params, once: '1', ...(cursor.current ? { since: cursor.current } : {}) }), { credentials: 'same-origin' });
                    const parsed = parseSse<T>(await res.text());
                    if (parsed.lastId) cursor.current = parsed.lastId;
                    push(parsed.rows);
                } catch {
                    /* keep polling */
                }
            };
            void tick();
            poll = setInterval(tick, 3000);
        };

        if (typeof EventSource === 'undefined') {
            startPolling();
            return () => { if (poll) clearInterval(poll); };
        }

        const source = new EventSource(apiUrl(`stream/${signal}`, { ...params, ...(cursor.current ? { since: cursor.current } : {}) }), { withCredentials: true });
        let failures = 0;
        setMode('sse');

        source.addEventListener('rows', (e) => {
            failures = 0;
            const ev = e as MessageEvent<string>;
            if (ev.lastEventId) cursor.current = ev.lastEventId;
            try {
                push((JSON.parse(ev.data) as { rows: T[] }).rows ?? []);
            } catch {
                /* malformed batch */
            }
        });

        source.onerror = () => {
            failures++;
            if (failures >= 3) {
                source.close();
                startPolling();
            }
        };

        return () => {
            source.close();
            if (poll) clearInterval(poll);
        };
    }, [enabled, signal, key]); // eslint-disable-line react-hooks/exhaustive-deps

    return { rows, mode };
}
