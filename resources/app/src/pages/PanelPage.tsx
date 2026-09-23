import { useParams } from '@tanstack/react-router';
import { usePage } from '../api/hooks';
import { useBoot } from '../components/DimensionValue';
import { PanelView } from '../components/panels/PanelView';
import { ErrorState, Skeleton } from '../components/States';
import { useTitle } from '../lib/title';

/** The `/p/$page` route. */
export function PanelPageRoute() {
    const { page } = useParams({ strict: false }) as { page?: string };

    return <PanelPage page={page ?? 'dashboard'} />;
}

/** Any registered page (Jobs, Queues, Cache, Statamic …) as a grid of panels. */
export function PanelPage({ page, params }: { page: string; params?: Record<string, string> }) {
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
                    {data && fillRows(data.panels).map((p) => <PanelView key={p.id} id={p.id} span={p.span} params={{ ...params, _page: page }} />)}
                </div>
            )}
        </div>
    );
}

/**
 * The grid is two columns: a half-width panel that would sit alone in its row
 * (the next one is full width, or it's the last) takes the whole row instead
 * of leaving a hole beside it.
 */
export function fillRows<T extends { span: number }>(panels: T[]): T[] {
    let column = 0;
    return panels.map((panel, i) => {
        const half = panel.span < 2;
        const next = panels[i + 1];
        const alone = half && column === 0 && (next === undefined || next.span >= 2);
        const span = half && !alone ? 1 : 2;
        column = (column + span) % 2;
        return alone ? { ...panel, span: 2 } : panel;
    });
}

export function OverviewPage() {
    return <PanelPage page="dashboard" />;
}

/** The full issue page: the error-detail panels scoped to one group. */
export function ErrorPage() {
    const { group } = useParams({ strict: false }) as { group: string };
    return <PanelPage page="error-detail" params={{ group }} />;
}
