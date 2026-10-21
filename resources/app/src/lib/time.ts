import { useSyncExternalStore } from 'react';
import { boot } from '../boot';
import { load, save } from './storage';

// The timezone every time on screen is shown in. The viewer's pick wins, then
// the host's default (`telemetry-ui.timezone`), then the browser's own, so
// times read as local time out of the box. The API is told the same zone
// (X-Timezone) and formats its own clocks in it, so a table row and the chart
// above it always agree.

/** The stored pick that means "follow this browser", whatever the host's default. */
export const BROWSER = 'browser';

const listeners = new Set<() => void>();

export function browserTimeZone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
}

/** What the viewer picked: an IANA name, {@link BROWSER}, or null for the default. */
export function timeZonePick(): string | null {
    const pick = load<string | null>('timezone', null);
    return typeof pick === 'string' && (pick === BROWSER || valid(pick)) ? pick : null;
}

/** The IANA zone to show times in now. */
export function timeZone(): string {
    const pick = timeZonePick();
    if (pick === BROWSER) return browserTimeZone();
    if (pick !== null) return pick;
    const host = boot().timezone;
    return host && valid(host) ? host : browserTimeZone();
}

/** Pick a zone (null = back to the default) and tell everything showing times. */
export function setTimeZone(pick: string | null): void {
    save('timezone', pick);
    for (const listener of listeners) listener();
}

/** The current zone, re-rendering when the viewer picks another. */
export function useTimeZone(): string {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        timeZone,
        timeZone,
    );
}

/** Every zone the browser knows, for the picker. */
export function timeZones(): string[] {
    try {
        const all = (Intl as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf?.('timeZone') ?? [];
        return all.includes('UTC') ? all : ['UTC', ...all];
    } catch {
        return ['UTC'];
    }
}

/** The zone's short name at `at`: CEST, GMT+2, UTC. */
export function zoneLabel(zone: string, at = Date.now()): string {
    try {
        const part = new Intl.DateTimeFormat('en-US', { timeZone: zone, timeZoneName: 'short' }).formatToParts(at).find((p) => p.type === 'timeZoneName');
        return part?.value ?? zone;
    } catch {
        return zone;
    }
}

function valid(zone: string): boolean {
    try {
        new Intl.DateTimeFormat('en-US', { timeZone: zone });
        return true;
    } catch {
        return false;
    }
}

export interface WallClock {
    year: number;
    month: number;
    day: number;
    hour: number;
    minute: number;
    second: number;
}

const formatters = new Map<string, Intl.DateTimeFormat>();

/** The wall clock in `zone` at the instant `ms`. */
export function wallClock(ms: number, zone = timeZone()): WallClock {
    let f = formatters.get(zone);
    if (!f) {
        f = new Intl.DateTimeFormat('en-US', {
            timeZone: zone, hourCycle: 'h23',
            year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', second: 'numeric',
        });
        formatters.set(zone, f);
    }
    const p: Record<string, number> = {};
    for (const part of f.formatToParts(ms)) {
        if (part.type !== 'literal') p[part.type] = Number(part.value);
    }
    const n = (type: string) => p[type] ?? 0;
    return { year: n('year'), month: n('month'), day: n('day'), hour: n('hour') % 24, minute: n('minute'), second: n('second') };
}

/** How far `zone` is ahead of UTC at the instant `ms`, in ms. */
function offset(ms: number, zone: string): number {
    const w = wallClock(ms, zone);
    return Date.UTC(w.year, w.month - 1, w.day, w.hour, w.minute, w.second) - Math.floor(ms / 1000) * 1000;
}

/** The instant a wall-clock time in `zone` names (a datetime-local value read in that zone). */
export function fromWallClock(w: WallClock, zone = timeZone()): number {
    const asUtc = Date.UTC(w.year, w.month - 1, w.day, w.hour, w.minute, w.second);
    // Twice: the first guess can sit across a daylight-saving change.
    const first = asUtc - offset(asUtc, zone);
    return asUtc - offset(first, zone);
}

/** `YYYY-MM-DDTHH:mm` for a datetime-local input, in `zone`. */
export function toInputValue(ms: number, zone = timeZone()): string {
    const w = wallClock(ms, zone);
    const pad = (x: number) => String(x).padStart(2, '0');
    return `${w.year}-${pad(w.month)}-${pad(w.day)}T${pad(w.hour)}:${pad(w.minute)}`;
}

/** A datetime-local input's value, read in `zone`; NaN when it is not one. */
export function fromInputValue(value: string, zone = timeZone()): number {
    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(value);
    if (!m) return Number.NaN;
    const [, year, month, day, hour, minute, second] = m.map(Number);
    return fromWallClock({ year: year ?? 0, month: month ?? 0, day: day ?? 0, hour: hour ?? 0, minute: minute ?? 0, second: Number.isNaN(second) ? 0 : (second ?? 0) }, zone);
}
