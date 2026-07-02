<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * Per-route request table: status classes, totals, avg and p95, with
 * drill-down links to matching traces.
 */
final class RoutesTable extends Card
{
    #[Url(as: 'route_search')]
    public string $search = '';

    public function render(): View
    {
        $p = $this->period()->promDuration();

        $count = $this->metric('http_server_request_duration_milliseconds_count');
        $sum = $this->metric('http_server_request_duration_milliseconds_sum');
        $bucket = $this->metric('http_server_request_duration_milliseconds_bucket');

        $rows = [];
        $error = null;

        try {
            $counts = $this->metrics()->query(
                'sum by (http_route, http_request_method, class) (label_replace(increase('.$count.'['.$p.']), "class", "${1}xx", "http_response_status_code", "([0-9]).."))',
            );

            $times = $this->metrics()->query(
                'sum by (http_route, http_request_method) (increase('.$sum.'['.$p.']))',
            );

            $p95s = $this->metrics()->query(
                'histogram_quantile(0.95, sum by (http_route, http_request_method, le) (rate('.$bucket.'['.$p.'])))',
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
            ];

            $class = match ($sample->labels['class'] ?? '') {
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
     * The traces-page URL pre-filtered to this route.
     */
    public function tracesUrl(string $route): string
    {
        return route('telemetry-ui.page', array_filter([
            'page' => 'traces',
            'q' => '{ '.$this->traceScope('span.http.route = "'.addcslashes($route, '"\\').'" && kind = server').' }',
            'period' => $this->period,
            'service' => $this->service,
            'env' => $this->environment,
        ]));
    }

    /**
     * @param  list<array{method: string, route: string, ok: float, '4xx': float, '5xx': float, total: float, time: float, p95: float|null}>  $rows
     */
    private function view(array $rows, ?string $error): View
    {
        /** @var view-string $view */
        $view = 'telemetry-ui::cards.routes-table';

        return view($view, ['rows' => $rows, 'error' => $error]);
    }
}
