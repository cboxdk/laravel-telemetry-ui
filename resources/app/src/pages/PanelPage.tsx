import { useParams } from '@tanstack/react-router';
import { usePage } from '../api/hooks';
import { useBoot } from '../components/DimensionValue';
import { PanelView } from '../components/panels/PanelView';
import { ErrorState, Skeleton } from '../components/States';
import { useTitle } from '../lib/title';

/** Any registered page (Jobs, Queues, Cache, Statamic …) as a grid of panels. */
export function PanelPage({ page: fixed, params }: { page?: string; params?: Record<string, string> }) {
    const routeParams = useParams({ strict: false }) as { page?: string };
    const page = fixed ?? routeParams.page ?? 'dashboard';
    const boot = useBoot();
    const { data, error, isLoading } = usePage(page);
    const meta = boot.pages[page];
    useTitle(data?.label ?? meta?.label ?? page);
    const headerFirst = data?.panels[0]?.id.endsWith('-header');

    return (
        <div className="t-page">
            {!headerFirst && (
                <header className="t-page-head">
                    <div>
                        {meta?.group && <div className="t-eyebrow">{meta.group}</div>}
                        <h1 className="t-page-title">{data?.label ?? meta?.label ?? page}</h1>
                    </div>
                </header>
            )}
            {isLoading && !data ? <Skeleton height={320} /> : error ? <ErrorState error={error} /> : (
                <div className="t-grid">
                    {data?.panels.map((p) => <PanelView key={p.id} id={p.id} span={p.span} params={{ ...params, _page: page }} />)}
                </div>
            )}
        </div>
    );
}

export function OverviewPage() {
    return <PanelPage page="dashboard" />;
}

/** The full issue page: the error-detail panels scoped to one group. */
export function ErrorPage() {
    const { group } = useParams({ strict: false }) as { group: string };
    return <PanelPage page="error-detail" params={{ group }} />;
}
