import { useState } from 'react';
import { createPortal } from 'react-dom';
import { usePlaceMonitor } from '../api/hooks';
import { useScope } from '../lib/state';
import { MONITOR_DURATIONS, type MonitorTarget } from '../lib/monitors';
import { useBoot } from './DimensionValue';
import { Icon } from './Icon';

/**
 * "Monitor…": for a while, keep every trace of one slice of a service's
 * traffic (a route, a user, a tenant), with detail and optionally a CPU
 * profile — a cboxdk/laravel-telemetry directive placed through
 * `POST /api/v2/monitors`. Whether the viewer may is the endpoint's call.
 */
export function MonitorDialog({ targets, service, onClose }: {
    /** One or more things to monitor; the viewer picks when there are several. */
    targets: MonitorTarget[];
    /** The service the value was seen on, preselected when monitorable. */
    service?: string | null;
    onClose: () => void;
}) {
    const boot = useBoot();
    const scope = useScope();
    const services = boot.monitors?.services ?? [];
    const preferred = [service, scope.service].find((s): s is string => Boolean(s) && services.includes(s as string));
    const [target, setTarget] = useState(0);
    const [chosenService, setService] = useState(preferred ?? services[0] ?? '');
    const [minutes, setMinutes] = useState(60);
    const [profile, setProfile] = useState(false);
    const [reason, setReason] = useState('');
    const place = usePlaceMonitor();
    const picked = targets[target];

    return createPortal(
        <div className="t-modal-backdrop" role="presentation" onMouseDown={(e) => e.target === e.currentTarget && onClose()} onClick={(e) => e.stopPropagation()}>
            <form
                className="t-modal t-monitor-dialog"
                role="dialog"
                aria-label="Monitor"
                onSubmit={(e) => {
                    e.preventDefault();
                    if (!picked) return;
                    place.mutate({ service: chosenService, match: picked.match, key: picked.key, value: picked.value, minutes, profile, reason });
                }}
            >
                <header className="t-modal-head">
                    <h3>Monitor</h3>
                    <button type="button" className="t-iconbtn" onClick={onClose} aria-label="Close"><Icon name="x" size={15} /></button>
                </header>

                {place.isSuccess ? (
                    <>
                        <p className="t-note">
                            Monitoring <strong>{place.data.monitor.describe}</strong> on <strong>{place.data.monitor.service}</strong> for the next {MONITOR_DURATIONS.find((d) => d.minutes === minutes)?.label ?? `${minutes} min`}.
                            Every matching trace is kept{place.data.monitor.profile ? ', with a CPU profile' : ''}, tagged <code>telemetry.directive = {place.data.monitor.id}</code>.
                        </p>
                        <footer className="t-row-gap t-modal-foot">
                            <button type="button" className="t-btn t-btn-primary" onClick={onClose}>Done</button>
                        </footer>
                    </>
                ) : (
                    <>
                        <p className="t-note">Keep every trace of this slice — whatever the sample rate — with its detail spans, until the monitor expires.</p>
                        {targets.length > 1 ? (
                            <fieldset className="t-field t-monitor-targets">
                                <legend>What</legend>
                                {targets.map((t, i) => (
                                    <label key={`${t.match}:${t.key}:${t.value}`} className="t-radio">
                                        <input type="radio" name="target" checked={target === i} onChange={() => setTarget(i)} />
                                        <span className="mono">{t.label}</span>
                                    </label>
                                ))}
                            </fieldset>
                        ) : (
                            <div className="t-field">What<span className="mono t-monitor-what">{picked?.label}</span></div>
                        )}
                        {services.length > 1 ? (
                            <label className="t-field">Service
                                <select className="t-input" value={chosenService} onChange={(e) => setService(e.target.value)}>
                                    {services.map((s) => <option key={s} value={s}>{s}</option>)}
                                </select>
                            </label>
                        ) : (
                            <div className="t-field">Service<span className="mono t-monitor-what">{chosenService}</span></div>
                        )}
                        <div className="t-field">For
                            <div className="t-seg" role="group" aria-label="Duration">
                                {MONITOR_DURATIONS.map((d) => (
                                    <button key={d.minutes} type="button" className={minutes === d.minutes ? 'is-on' : ''} aria-pressed={minutes === d.minutes} onClick={() => setMinutes(d.minutes)}>{d.label}</button>
                                ))}
                            </div>
                        </div>
                        <label className="t-check">
                            <input type="checkbox" checked={profile} onChange={(e) => setProfile(e.target.checked)} />
                            With CPU profile
                        </label>
                        <label className="t-field">Reason<input className="t-input" value={reason} maxLength={200} onChange={(e) => setReason(e.target.value)} placeholder="Ticket 881: checkout slow for this customer" /></label>
                        {place.error && <p className="t-panel-error">{place.error.message}</p>}
                        <footer className="t-row-gap t-modal-foot">
                            <button type="button" className="t-btn t-btn-ghost" onClick={onClose}>Cancel</button>
                            <button type="submit" className="t-btn t-btn-primary" disabled={place.isPending || !picked || chosenService === ''}>{place.isPending ? 'Starting…' : 'Start monitoring'}</button>
                        </footer>
                    </>
                )}
            </form>
        </div>,
        document.body,
    );
}
