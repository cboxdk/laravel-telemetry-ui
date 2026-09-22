<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Per-route request table: status classes, totals, avg and p95, with
 * drill-down links to matching traces.
 *
 * @phpstan-type RouteRow array{method: string, route: string, ok: float, '4xx': float, '5xx': float, total: float, time: float, p95: float|null, spark: list<float>}
 */
class RoutesTable extends Panel
{
    #[Param('route_search')]
    public string $search = '';

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();
        $p = $this->promDuration();

        $count = $this->metric('http_server_request_duration_seconds_count');
        $sum = $this->metric('http_server_request_duration_seconds_sum');
        $bucket = $this->metric('http_server_request_duration_seconds_bucket');

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
            return $this->payload([], $exception->getMessage());
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
                // v2 duration histogram is in seconds; ×1000 → ms for the view.
                $rows[$key]['time'] = $sample->value * 1000;
            }
        }

        foreach ($p95s as $sample) {
            $key = ($sample->labels['http_request_method'] ?? '?').' '.($sample->labels['http_route'] ?? '?');

            if (isset($rows[$key]) && ! is_nan($sample->value)) {
                $rows[$key]['p95'] = $sample->value * 1000;
            }
        }

        // increase() extrapolation leaves near-zero ghosts at period edges.
        $rows = array_filter($rows, static fn (array $row): bool => $row['total'] >= 0.5);

        if ($this->search !== '') {
            $rows = array_filter($rows, fn (array $row): bool => stripos($row['method'].' '.$row['route'], $this->search) !== false);
        }

        return $this->payload(array_values(array_slice($this->rank(array_values($rows)), 0, $this->limit())), $error);
    }

    /**
     * @param  list<array{method: string, route: string, ok: float, '4xx': float, '5xx': float, total: float, time: float, p95: float|null, spark: list<float>}>  $rows
     * @return array<string, mixed>
     */
    private function payload(array $rows, ?string $error): array
    {
        $table = [];

        foreach ($rows as $row) {
            // The purpose-built detail page for this route (its own throughput,
            // latency, error rate and traces) — not a pre-filtered trace search.
            $link = Ui::entity('route', $row['route']);

            $table[] = [
                'method' => Ui::cell($row['method'], ['badge' => $row['method'], 'tone' => $row['method'] === 'GET' ? 'info' : 'ok']),
                'route' => Ui::cell($row['route'], ['mono' => true, 'link' => $link, 'dim' => ['key' => 'http.route', 'value' => $row['route']]]),
                'trend' => Ui::cell(null, ['spark' => $row['spark'], 'tone' => $row['5xx'] > 0 ? 'danger' : ($row['4xx'] > 0 ? 'warn' : 'ok')]),
                'ok' => Ui::cell(Format::count($row['ok']), ['raw' => $row['ok']]),
                '4xx' => Ui::cell(Format::count($row['4xx']), ['raw' => $row['4xx'], 'tone' => $row['4xx'] > 0 ? 'warn' : null]),
                '5xx' => Ui::cell(Format::count($row['5xx']), ['raw' => $row['5xx'], 'tone' => $row['5xx'] > 0 ? 'danger' : null]),
                'total' => Ui::cell(Format::count($row['total']), ['raw' => $row['total']]),
                'avg' => $row['total'] > 0
                    ? Ui::cell(Format::ms($row['time'] / $row['total']), ['raw' => $row['time'] / $row['total']])
                    : Ui::cell('—'),
                'p95' => $row['p95'] !== null ? Ui::cell(Format::ms($row['p95']), ['raw' => $row['p95']]) : Ui::cell('—'),
                '_link' => $link,
            ];
        }

        return Ui::table($this->tableTitle(), [
            Ui::col('method', 'Method'),
            Ui::col('route', 'Route'),
            Ui::col('trend', 'Trend'),
            Ui::num('ok', '1/2/3XX'),
            Ui::num('4xx', '4XX'),
            Ui::num('5xx', '5XX'),
            Ui::num('total', 'Total'),
            Ui::num('avg', 'AVG'),
            Ui::num('p95', 'P95'),
        ], $table, array_filter([
            'subtitle' => $this->tableSubtitle(),
            'error' => $error,
            'empty' => 'No requests in this period.',
            'controls' => $this->searchable() ? [Ui::search('route_search', 'Search', $this->search, 'Search routes…')] : null,
            'drill' => $this->drillLink(),
        ], static fn ($v): bool => $v !== null));
    }

    /**
     * Row order: busiest first.
     *
     * @param  list<RouteRow>  $rows
     * @return list<RouteRow>
     */
    protected function rank(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $rows;
    }

    protected function limit(): int
    {
        return 100;
    }

    protected function searchable(): bool
    {
        return true;
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
