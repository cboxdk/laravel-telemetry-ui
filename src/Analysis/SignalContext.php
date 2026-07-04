<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Cbox\TelemetryUi\Queries\Results\Trace;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Correlates a slice of telemetry (a trace, a time window) with the host and
 * runtime signals recorded around it — the thing an app-only monitor can't do
 * but we can, because the same Prometheus scrapes system and process metrics
 * (and node_exporter, mysqld_exporter, … when present) right next to the app.
 *
 * Config-driven: each `telemetry-ui.context.signals` entry is a PromQL
 * template with a `{scope}` token that expands to the matcher list for the
 * scope (service_name, host_name, …). Signals resolve independently and
 * fail-open, so a missing exporter just means one fewer tile — never an error.
 */
final readonly class SignalContext
{
    public function __construct(
        private ConnectionManager $connections,
        private Config $config,
        private CacheFactory $cache,
    ) {}

    /**
     * Host/runtime context around a single trace: scope from its root service
     * and host, window padded around the trace so metric samples land in it.
     *
     * @return list<MetricSummary>
     */
    public function forTrace(Trace $trace): array
    {
        $root = $trace->root();

        if ($root === null || $root->serviceName === '') {
            return [];
        }

        $scope = ['service_name' => $root->serviceName];

        $host = $trace->services[$root->serviceName]['host.name'] ?? null;
        if (is_string($host) && $host !== '') {
            $scope['host_name'] = $host;
        }

        [$start, $end] = $this->paddedWindow($trace);

        return $this->for($scope, $start, $end);
    }

    /**
     * @param  array<string, string>  $scope  label => value, e.g. ['service_name' => 'cbox-web']
     * @return list<MetricSummary>
     */
    public function for(array $scope, DateTimeInterface $start, DateTimeInterface $end): array
    {
        if (! (bool) $this->config->get('telemetry-ui.context.enabled', true)) {
            return [];
        }

        $signals = $this->config->get('telemetry-ui.context.signals');
        if (! is_array($signals)) {
            return [];
        }

        $selector = $this->selector($scope);

        // Baseline lookback ends where the window starts, so "typical" is the
        // recent normal — not contaminated by the spike we're inspecting.
        $lookback = max(300, (int) $this->config->get('telemetry-ui.context.baseline_window', 21_600));
        $baselineStart = (new DateTimeImmutable('@'.$start->getTimestamp()))->modify('-'.$lookback.' seconds');

        $out = [];

        foreach ($signals as $signal) {
            if (! is_array($signal) || ! is_string($signal['query'] ?? null)) {
                continue;
            }

            $summary = $this->resolve($signal, $selector, $start, $end, $baselineStart);
            if ($summary !== null) {
                $out[] = $summary;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $signal
     */
    private function resolve(array $signal, string $selector, DateTimeInterface $start, DateTimeInterface $end, DateTimeInterface $baselineStart): ?MetricSummary
    {
        $query = $this->expand((string) $signal['query'], $selector);

        try {
            $series = $this->connections->metrics()->queryRange($query, $start, $end);
        } catch (SourceException) {
            return null; // fail-open: a missing signal is one fewer tile.
        }

        $points = $this->points($series);

        if ($points === [] || max(array_map('abs', $points)) === 0.0) {
            return null; // no signal here — don't render an empty tile.
        }

        $group = is_string($signal['group'] ?? null) ? $signal['group'] : 'custom';

        return new MetricSummary(
            label: is_string($signal['label'] ?? null) ? $signal['label'] : $query,
            group: in_array($group, ['host', 'runtime', 'db', 'cache', 'custom'], true) ? $group : 'custom',
            unit: is_string($signal['unit'] ?? null) ? $signal['unit'] : 'number',
            current: $points[count($points) - 1],
            avg: array_sum($points) / count($points),
            max: max($points),
            points: $points,
            baseline: $this->baseline($query, $baselineStart, $start),
        );
    }

    /**
     * The typical value of a signal over the lookback window (its average), or
     * null when there's no history to compare against. A baseline is a
     * slow-changing multi-hour average, so it's cached far longer than the live
     * query cache and keyed to a coarse time bucket — nearby traces share it,
     * instead of each re-running the (expensive) lookback query.
     */
    private function baseline(string $query, DateTimeInterface $start, DateTimeInterface $end): ?float
    {
        $ttl = max(30, (int) $this->config->get('telemetry-ui.context.baseline_ttl', 120));
        $bucket = intdiv($end->getTimestamp(), 300) * 300;
        $key = 'telemetry-ui:baseline:'.hash('xxh128', $query.'|'.$bucket);

        return $this->cache->store()->remember($key, $ttl, function () use ($query, $start, $end): ?float {
            try {
                $points = $this->points($this->connections->metrics()->queryRange($query, $start, $end));
            } catch (SourceException) {
                return null;
            }

            return $points === [] ? null : array_sum($points) / count($points);
        });
    }

    /**
     * @param  list<TimeSeries>  $series
     * @return list<float>
     */
    private function points(array $series): array
    {
        $points = [];
        foreach ($series[0]->points ?? [] as $point) {
            $points[] = $point->value;
        }

        return $points;
    }

    /**
     * @param  array<string, string>  $scope
     */
    private function selector(array $scope): string
    {
        $parts = [];
        foreach ($scope as $label => $value) {
            if ($value !== '') {
                $parts[] = $label.'="'.addcslashes($value, '"\\').'"';
            }
        }

        return implode(',', $parts);
    }

    private function expand(string $query, string $selector): string
    {
        $query = str_replace('{scope}', $selector, $query);

        // When the scope is empty, tidy the stray commas an empty {scope} leaves.
        $query = preg_replace('/\{\s*,\s*/', '{', $query) ?? $query;

        return preg_replace('/,\s*\}/', '}', $query) ?? $query;
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function paddedWindow(Trace $trace): array
    {
        $pad = max(60, (int) $this->config->get('telemetry-ui.context.window', 600)) / 2;

        $starts = array_map(static fn ($s): int => $s->startNano, $trace->spans);
        $ends = array_map(static fn ($s): int => $s->endNano, $trace->spans);

        $startNano = $starts === [] ? 0 : min($starts);
        $endNano = $ends === [] ? $startNano : max($ends);

        return [
            (new DateTimeImmutable('@'.intdiv($startNano, 1_000_000_000)))->modify('-'.$pad.' seconds'),
            (new DateTimeImmutable('@'.intdiv($endNano, 1_000_000_000)))->modify('+'.$pad.' seconds'),
        ];
    }
}
