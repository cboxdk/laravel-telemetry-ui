import { useParams } from '@tanstack/react-router';
import { TraceView } from '../components/drawer/TraceView';
import { useTitle } from '../lib/title';

export function TracePage() {
    const { traceId } = useParams({ strict: false }) as { traceId: string };
    useTitle(`Trace ${traceId.slice(0, 8)}`);
    return (
        <div className="t-page t-trace-page">
            <TraceView traceId={traceId} full />
        </div>
    );
}
