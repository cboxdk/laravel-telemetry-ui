/**
 * When each trace the reader has seen in a list started (epoch milliseconds),
 * so opening it can tell the trace store where to look: a lookup within an
 * hour of the trace is several times faster than one across retention. Links
 * carry the time as `at`; this keeps it for the drawer, whose URL holds only
 * the id. A trace opened with no time known (a pasted id, a fresh deep link)
 * is looked up in full, exactly as before.
 */
const times = new Map<string, number>();

/** Enough for every row of every list a session plausibly opens. */
const LIMIT = 2000;

export function rememberTraceTime(id: string, at: number | undefined): void {
    if (at === undefined || !Number.isFinite(at) || at <= 0) return;

    if (!times.has(id) && times.size >= LIMIT) {
        const oldest = times.keys().next().value;
        if (oldest !== undefined) times.delete(oldest);
    }

    times.set(id, Math.round(at));
}

export function traceTime(id: string): number | undefined {
    return times.get(id);
}
