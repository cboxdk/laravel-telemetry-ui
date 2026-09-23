import { ApiError } from '../api/client';
import { Icon } from './Icon';

export function Spinner({ label }: { label?: string }) {
    return (
        <div className="t-spinner" role="status">
            <span className="t-spinner-dot" />
            {label && <span>{label}</span>}
        </div>
    );
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
