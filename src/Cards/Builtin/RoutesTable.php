<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * Per-route request table: status classes, totals, avg and p95, with
 * drill-down links to matching traces.
 */
class RoutesTable extends Card
{
    #[Url(as: 'route_search')]
    public string $search = '';

    /** Shared toggle with the RequestLog sibling: 'routes' | 'log'. */
    #[Url(as: 'req_view')]
    public string $view = 'routes';

    /**
     * The Routes/Request log toggle is a Livewire event (not a page link) so
     * flipping views never reloads the page — both sibling cards listen.
     */
    #[On('telemetry-ui:request-view-changed')]
    public function updateRequestView(string $view): void
    {
        $this->view = $view === 'log' ? 'log' : 'routes';
    }

    public function render(): View
    {
        if ($this->view === 'log') {
            /** @var view-string $hidden */
            $hidden = 'telemetry-ui::cards.hidden';

            return view($hidden);
        }

        [$start, $end] = $this->range();
        $p = $this->promDuration();

        $count = $this->metric('http_server_request_duration_milliseconds_count');
        $sum = $this->metric('http_server_request_duration_milliseconds_sum');
        $bucket = $this->metric('http_server_request_duration_milliseconds_bucket');

        $rows = [];
        $error = null;
        $trends = [];

        try {
            $counts = $this->metrics()->query(
                $count->increase($p)->sumBy('http_route', 'http_request_method', 'http_response_status_code'),
            );

            $times = $this->metrics()->query(
                $sum->increase($p)->sumBy('http_route', 'http_request_method'),
            );

            $p95s = $this->metrics()->query(
                $bucket->quantile(0.95, $p, 'http_route', 'http_request_method'),
            );

            $trends = $this->trendByKey(
                $count->rate($this->rateWindow())->sumBy('http_route', 'http_request_method')->times(60),
                $start,
                $end,
                fn (array $labels): string => ($labels['http_request_method'] ?? '?').' '.($labels['http_route'] ?? '?'),
            );
        } catch (SourceException $exception) {
            return $this->view($rows, $exception->getMessage());
        }

        foreach ($counts as $sample) {
            $key = ($sample->labels['http_request_method'] ?? '?').' '.($sample->labels['http_route'] ?? '?');

            $rows[$key] ??= [
                'method' => $sample->labels['http_request_method'] ?? '?',
                'route' => $sample->labels['http_route'] ?? '?',
                'ok' => 0.0, '4xx' => 0.0, '5xx' => 0.0, 'total' => 0.0,
                'time' => 0.0, 'p95' => null,
                'spark' => $trends[$key] ?? [],
            ];

            $code = $sample->labels['http_response_status_code'] ?? '';
            $class = match ($code === '' ? '' : $code[0].'xx') {
                '4xx' => '4xx',
                '5xx' => '5xx',
                default => 'ok',
            };

            $rows[$key][$class] += $sample->value;
            $rows[$key]['total'] += $sample->value;
        }

        foreach ($times as $sample) {
            $key = ($sample->labels['http_request_method'] ?? '?').' '.($sample->labels['http_route'] ?? '?');

            if (isset($rows[$key])) {
                $rows[$key]['time'] = $sample->value;
            }
        }

        foreach ($p95s as $sample) {
            $key = ($sample->labels['http_request_method'] ?? '?').' '.($sample->labels['http_route'] ?? '?');

            if (isset($rows[$key]) && ! is_nan($sample->value)) {
                $rows[$key]['p95'] = $sample->value;
            }
        }

        // increase() extrapolation leaves near-zero ghosts at period edges.
        $rows = array_filter($rows, static fn (array $row): bool => $row['total'] >= 0.5);

        if ($this->search !== '') {
            $rows = array_filter($rows, fn (array $row): bool => stripos($row['method'].' '.$row['route'], $this->search) !== false);
        }

        usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $this->view(array_slice($rows, 0, 100), $error);
    }

    /**
     * The purpose-built detail page for this route (its own throughput,
     * latency, error rate and traces) — not a pre-filtered trace search.
     */
    public function detailUrl(string $route): string
    {
        return $this->pageUrl('request-detail', ['route' => $route]);
    }

    /**
     * @param  list<array{method: string, route: string, ok: float, '4xx': float, '5xx': float, total: float, time: float, p95: float|null, spark?: list<float>}>  $rows
     */
    private function view(array $rows, ?string $error): View
    {
        /** @var view-string $view */
        $view = 'telemetry-ui::cards.routes-table';

        return view($view, [
            'rows' => $rows,
            'error' => $error,
            'title' => $this->tableTitle(),
            'subtitle' => $this->tableSubtitle(),
        ]);
    }

    protected function tableTitle(): string
    {
        return 'Routes';
    }

    protected function tableSubtitle(): string
    {
        return 'Per-route request volume, status mix and latency — click a route for its detail page';
    }
}
