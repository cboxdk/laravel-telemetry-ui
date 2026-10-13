import { useEffect, useState } from 'react';
import { useMonitors, useRemoveMonitor } from '../api/hooks';
import type { MonitorData } from '../api/types';
import { useBoot } from '../components/DimensionValue';
import { Icon } from '../components/Icon';
import { Empty, ErrorState, Skeleton } from '../components/States';
import { Go } from '../lib/links';
import { expiresIn, MONITOR_ATTRIBUTE, RECORDED_BY_MONITOR } from '../lib/monitors';
import { useTitle } from '../lib/title';

/** Seconds, ticking: the countdowns move without refetching. */
function useNow(serverNow: number | undefined): number {
    const [offset, setOffset] = useState(0);
    const [now, setNow] = useState(() => Date.now() / 1000);

    // Count down against the server's clock: the expiry is the monitored
    // app's, and a browser a few minutes off would otherwise say "expired".
    useEffect(() => {
        if (serverNow !== undefined) setOffset(serverNow - Date.now() / 1000);
    }, [serverNow]);

    useEffect(() => {
        const t = setInterval(() => setNow(Date.now() / 1000), 1000);
        return () => clearInterval(t);
    }, []);

    return now + offset;
}

/**
 * Active monitors: every directive this viewer may see, on every service it
 * may monitor — what it matches, how long it has left, why, who placed it,
 * the traces it has recorded so far, and a way to stop it early.
 */
export function MonitorsPage() {
    useTitle('Monitors');
    const boot = useBoot();
    const { data, error, isLoading } = useMonitors();
    const remove = useRemoveMonitor();
    const now = useNow(data?.now);
    const monitors = (data?.monitors ?? []).filter((m) => m.expiresAt > now);

    return (
        <div className="t-page">
            <header className="t-page-head">
                <div>
                    <h1 className="t-page-title">Monitors</h1>
                    <p className="t-page-sub">Slices of traffic recorded in full for a while — every matching trace kept, with detail, optionally profiled.</p>
                </div>
                <Go link={{ to: 'explore', signal: 'requests', where: [`${RECORDED_BY_MONITOR.key}${RECORDED_BY_MONITOR.op}${RECORDED_BY_MONITOR.value}`] }} className="t-btn t-btn-sm t-btn-secondary">
                    Everything monitors recorded <Icon name="chevronRight" size={12} />
                </Go>
            </header>

            {isLoading && <Skeleton height={160} />}
            {error && <ErrorState error={error} />}

            {data && Object.entries(data.errors).map(([service, message]) => (
                <p key={service} className="t-panel-error"><strong>{service}</strong>: {message}</p>
            ))}

            {data && data.services.length === 0 && (
                <Empty>
                    No service can be monitored from here. This app needs cboxdk/laravel-telemetry 3.3+ with directives on, or
                    a service listed under <code>telemetry-ui.monitors.services</code> with its directives secret.
                </Empty>
            )}

            {data && data.services.length > 0 && monitors.length === 0 && (
                <Empty>
                    No active monitors. Start one from a trace, or from a route, user or {boot.dimensions.some((d) => !d.builtin) ? 'tenant' : 'context'} value’s menu: <em>Monitor…</em>
                </Empty>
            )}

            {monitors.length > 0 && (
                <div className="t-monitors" role="table" aria-label="Active monitors">
                    <div className="t-monitor-row t-monitor-headrow" role="row">
                        <span role="columnheader">Monitoring</span>
                        <span role="columnheader">Service</span>
                        <span role="columnheader">Expires</span>
                        <span role="columnheader">Reason</span>
                        <span role="columnheader">By</span>
                        <span role="columnheader" />
                    </div>
                    {monitors.map((m) => (
                        <MonitorRow
                            key={`${m.service}:${m.id}`}
                            monitor={m}
                            now={now}
                            canManage={data?.canManage ?? false}
                            removing={remove.isPending && remove.variables?.id === m.id}
                            onRemove={() => remove.mutate({ service: m.service, id: m.id })}
                        />
                    ))}
                </div>
            )}
            {remove.error && <p className="t-panel-error">{remove.error.message}</p>}
        </div>
    );
}

function MonitorRow({ monitor: m, now, canManage, removing, onRemove }: { monitor: MonitorData; now: number; canManage: boolean; removing: boolean; onRemove: () => void }) {
    const left = m.expiresAt - now;
    return (
        <div className="t-monitor-row" role="row">
            <span role="cell" className="t-monitor-what">
                <span className="t-badge t-badge-dim">{m.match}</span>
                <span className="mono t-ellipsis" title={m.describe}>{m.match === 'context' ? `${m.key} = ${m.value}` : m.value}</span>
                {m.profile && <span className="t-badge t-badge-info" title="Also captures a CPU profile">profile</span>}
            </span>
            <span role="cell" className="mono">{m.service}</span>
            <span role="cell" className={`mono ${left < 300 ? 't-tone-warn' : ''}`} title={new Date(m.expiresAt * 1000).toLocaleString()}>{expiresIn(left)}</span>
            <span role="cell" className="t-ellipsis" title={m.reason ?? undefined}>{m.reason ?? <span className="t-dim">—</span>}</span>
            <span role="cell" className="t-ellipsis t-dim" title={m.createdBy ?? undefined}>{m.createdBy ?? '—'}</span>
            <span role="cell" className="t-row-gap t-monitor-actions">
                <Go link={{ to: 'explore', signal: 'requests', where: [`${MONITOR_ATTRIBUTE}=${m.id}`] }} className="t-linkbtn">Traces</Go>
                {canManage && (
                    <button type="button" className="t-btn t-btn-sm t-btn-ghost" disabled={removing} onClick={onRemove} aria-label={`Stop monitoring ${m.describe}`}>
                        {removing ? 'Stopping…' : 'Stop'}
                    </button>
                )}
            </span>
        </div>
    );
}
