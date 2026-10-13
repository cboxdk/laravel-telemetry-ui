import type { Bootstrap, DimensionDef, MonitorMatch } from '../api/types';

/** What a monitor would match, from a value the viewer is looking at. */
export interface MonitorTarget {
    match: MonitorMatch;
    key: string | null;
    value: string;
    /** "Route checkout.show", "User 42", "Tenant = 7". */
    label: string;
}

/** The durations offered, in minutes: the core caps a directive at a day. */
export const MONITOR_DURATIONS: { minutes: number; label: string }[] = [
    { minutes: 15, label: '15m' },
    { minutes: 60, label: '1h' },
    { minutes: 240, label: '4h' },
    { minutes: 1440, label: '24h' },
];

/** The span attribute a monitor's traces carry; `=~.+` is "recorded by any monitor". */
export const MONITOR_ATTRIBUTE = 'telemetry.directive';
export const RECORDED_BY_MONITOR = { key: MONITOR_ATTRIBUTE, op: '=~' as const, value: '.+' };

const BUILTIN: Record<string, { match: MonitorMatch; label: string }> = {
    'http.route': { match: 'route', label: 'Route' },
    'url.path': { match: 'path', label: 'Path' },
    'user.id': { match: 'user', label: 'User' },
    'laravel.job.class': { match: 'job', label: 'Job' },
};

/**
 * The monitor a dimension value maps to: a route, path, user or job for the
 * built-ins the core can match, and a context directive for a declared
 * (host) dimension — `Telemetry::context()` keys such as `tenant.id`.
 * Anything else (a status code, a host) is not something a directive can
 * match, so null.
 */
export function monitorTarget(key: string, value: string, dims: DimensionDef[]): MonitorTarget | null {
    if (value === '' || value === '(none)') return null;

    const builtin = BUILTIN[key];
    if (builtin) {
        // The core matches paths without the leading slash.
        const v = builtin.match === 'path' ? value.replace(/^\/+/, '') : value;
        return v === '' ? null : { match: builtin.match, key: null, value: v, label: `${builtin.label} ${v}` };
    }

    const dim = dims.find((d) => d.key === key);
    if (dim && !dim.builtin && !dim.derivedFrom && dim.scope === 'span' && /^[a-z][a-z0-9._]*$/.test(key)) {
        return { match: 'context', key, value, label: `${dim.label} = ${value}` };
    }

    return null;
}

/** Whether this viewer can place monitors at all. */
export function canMonitor(boot: Bootstrap | null | undefined): boolean {
    return Boolean(boot?.abilities.manage && (boot.monitors?.services.length ?? 0) > 0);
}

/** The monitorable targets on a trace's root (and its spans): user, route, declared context dimensions. */
export function traceTargets(attrs: Record<string, string>, dims: DimensionDef[]): MonitorTarget[] {
    const keys = ['user.id', 'http.route', ...dims.filter((d) => !d.builtin).map((d) => d.key)];
    return keys
        .map((k) => (attrs[k] ? monitorTarget(k, attrs[k]!, dims) : null))
        .filter((t): t is MonitorTarget => t !== null);
}

/** "in 14 min", "in 3 h 05 min", "expired". */
export function expiresIn(seconds: number): string {
    if (seconds <= 0) return 'expired';
    if (seconds < 60) return `in ${Math.ceil(seconds)} s`;
    const minutes = Math.max(1, Math.round(seconds / 60));
    if (minutes < 60) return `in ${minutes} min`;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m === 0 ? `in ${h} h` : `in ${h} h ${String(m).padStart(2, '0')} min`;
}
