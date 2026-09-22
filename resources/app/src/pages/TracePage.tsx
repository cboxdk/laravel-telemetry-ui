import { useParams } from '@tanstack/react-router';
import { TraceView } from '../components/drawer/TraceView';

export function TracePage() {
    const { traceId } = useParams({ strict: false }) as { traceId: string };
    return (
        <div className="t-page t-trace-page">
            <TraceView traceId={traceId} full />
        </div>
    );
}
