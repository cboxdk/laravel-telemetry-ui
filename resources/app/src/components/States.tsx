import { useIsFetching } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { ApiError } from '../api/client';
import { Icon } from './Icon';

/**
 * `active`, but only once it has held for `delay` ms — a read the cache or a
 * fast backend answers at once never flashes an indicator.
 */
export function useDelayed(active: boolean, delay = 150): boolean {
    const [shown, setShown] = useState(false);
    useEffect(() => {
        if (!active) {
            setShown(false);
            return;
        }
        const timer = setTimeout(() => setShown(true), delay);
        return () => clearTimeout(timer);
    }, [active, delay]);
    return active && shown;
}

/** Whole seconds since `active` turned on (0 while off). */
export function useElapsed(active: boolean): number {
    const [seconds, setSeconds] = useState(0);
    useEffect(() => {
        setSeconds(0);
        if (!active) return;
        const started = Date.now();
        const timer = setInterval(() => setSeconds(Math.floor((Date.now() - started) / 1000)), 1000);
        return () => clearInterval(timer);
    }, [active]);
    return seconds;
}

/** "· 4s" once a read has taken long enough that the count reassures. */
function elapsedLabel(seconds: number): string {
    return seconds >= 2 ? ` · ${seconds}s` : '';
}

export function Spinner({ label }: { label?: string }) {
    return (
        <div className="t-spinner" role="status">
            <span className="t-spinner-dot" />
            {label && <span>{label}</span>}
        </div>
    );
}

/**
 * A read that can take seconds (a trace-store search, a log scan): say what is
 * being read, above a placeholder the full width of what will replace it — so
 * a slow backend reads as "working", not as a broken, empty page.
 */
export function Loading({ label, height = 320 }: { label: string; height?: number }) {
    const seconds = useElapsed(true);
    return (
        <div className="t-loading">
            <Spinner label={label + elapsedLabel(seconds)} />
            <Skeleton height={height} />
        </div>
    );
}

/**
 * A read in flight behind data that is still on screen (a filter or window
 * changed, the old answer stays until the new one lands): say so, and how
 * long it has taken, so a slow backend reads as working rather than stuck.
 */
export function Busy({ active, label }: { active: boolean; label: string }) {
    const shown = useDelayed(active);
    const seconds = useElapsed(shown);
    if (!shown) return null;
    return (
        <span className="t-busy" role="status">
            <span className="t-spinner-dot" />
            {label + elapsedLabel(seconds)}
        </span>
    );
}

/**
 * The shell's progress line: moves while any read the viewer is waiting on is
 * in flight (prefetches, live tails and name lookups are marked `QUIET`).
 */
export function FetchBar() {
    const fetching = useIsFetching({ predicate: (query) => query.meta?.quiet !== true });
    const shown = useDelayed(fetching > 0);
    return <div className={`t-fetchbar ${shown ? 'is-on' : ''}`} role="progressbar" aria-label="Loading" aria-hidden={!shown} />;
}

export function Skeleton({ height = 160 }: { height?: number }) {
    return <div className="t-skeleton" style={{ height }} aria-hidden="true" />;
}

/** Typed error states: backend down ≠ forbidden ≠ not found ≠ empty. */
export function ErrorState({ error, compact }: { error: unknown; compact?: boolean }) {
    const e = error instanceof ApiError ? error : null;
    const title = !e
        ? 'Something went wrong'
        : e.type === 'backend'
          ? 'Backend unavailable'
          : e.type === 'forbidden'
            ? 'Not allowed'
            : e.type === 'not_found'
              ? 'Not found'
              : e.type === 'network'
                ? 'Offline'
                : e.type === 'invalid'
                  ? 'Invalid request'
                  : 'Something went wrong';
    const message = error instanceof Error ? error.message : 'Unknown error.';

    return (
        <div className={`t-state t-state-${e?.type ?? 'unknown'} ${compact ? 'is-compact' : ''}`} role="alert">
            <Icon name={e?.type === 'forbidden' ? 'user' : 'alert'} size={compact ? 14 : 18} />
            <div>
                <strong>{title}</strong>
                <p>{message}</p>
            </div>
        </div>
    );
}

export function Empty({ children }: { children: React.ReactNode }) {
    return <div className="t-empty">{children}</div>;
}
