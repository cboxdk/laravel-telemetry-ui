<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Discovery\Catalogue;
use Cbox\TelemetryUi\Discovery\DiscoveredTarget;
use Cbox\TelemetryUi\Discovery\Discoverer;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Support\ScopeLabels;
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
        private ?Discoverer $discoverer = null,
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

        $scope = [ScopeLabels::metrics('service') => $root->serviceName];
        $resource = $trace->services[$root->serviceName] ?? [];

        $host = $resource[ScopeLabels::traceResourceKey('host')] ?? null;
        $host = is_string($host) ? $host : '';
        if ($host !== '') {
            $scope[ScopeLabels::metrics('host')] = $host;
        }

        $environment = $resource[ScopeLabels::traceResourceKey('environment')] ?? null;

        [$start, $end] = $this->paddedWindow($trace);

        $own = $this->for($scope, $start, $end, [
            'service' => $root->serviceName,
            'host' => $host,
            'environment' => is_string($environment) ? $environment : '',
        ]);

        // Everything above comes from what the app emits about itself. The
        // exporters on the box and on the things it called know more, and
        // discovery already worked out which of them describe this trace.
        return [...$own, ...$this->discovered(self::namesIn($trace, $host), $start, $end)];
    }

    /**
     * The host a trace ran on, plus every downstream it called — the names
     * discovery matches exporters to.
     *
     * @return list<string>
     */
    private static function namesIn(Trace $trace, string $host): array
    {
        $names = $host === '' ? [] : [$host];

        foreach ($trace->spans as $span) {
            $address = $span->attributes['server.address'] ?? null;

            if (! is_string($address) || trim($address) === '') {
                continue;
            }

            $port = $span->attributes['server.port'] ?? null;
            $names[] = is_scalar($port) && (string) $port !== '' ? $address.':'.$port : $address;
        }

        return array_values(array_unique($names));
    }

    /**
     * What the discovered exporters say about these hosts and dependencies
     * over the same window.
     *
     * Only signals discovery already saw return data are asked for, so a
     * trace view never pays for a metric this deployment does not have.
     *
     * @param  list<string>  $names
     * @return list<MetricSummary>
     */
    public function discovered(array $names, DateTimeInterface $start, DateTimeInterface $end): array
    {
        if ($this->discoverer === null || $names === [] || ! (bool) $this->config->get('telemetry-ui.context.enabled', true)) {
            return [];
        }

        try {
            $map = $this->discoverer->map();
        } catch (SourceException) {
            return [];
        }

        $lookback = max(300, (int) $this->config->get('telemetry-ui.context.baseline_window', 21_600));
        $baselineStart = (new DateTimeImmutable('@'.$start->getTimestamp()))->modify('-'.$lookback.' seconds');

        $out = [];
        $seen = [];

        foreach ($names as $name) {
            foreach ($map->for($name) as $target) {
                $key = $target->exporter.'|'.$target->instance;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $out = [...$out, ...$this->targetSignals($target, $start, $end, $baselineStart)];
            }
        }

        return $out;
    }

    /**
     * @return list<MetricSummary>
     */
    private function targetSignals(DiscoveredTarget $target, DateTimeInterface $start, DateTimeInterface $end, DateTimeInterface $baselineStart): array
    {
        $exporter = Catalogue::find($target->exporter);

        if ($exporter === null) {
            return [];
        }

        $group = $exporter->describes;
        $out = [];

        foreach ($exporter->signals as $signal) {
            if (! in_array($signal->key, $target->signals, true)) {
                continue;
            }

            $summary = $this->resolve(
                ['label' => $signal->label, 'group' => $group, 'unit' => $signal->unit],
                $signal->promql($target->selector),
                $start,
                $end,
                $baselineStart,
            );

            if ($summary !== null) {
                $out[] = $summary;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $scope  label => value, e.g. ['service_name' => 'cbox-web']
     * @param  array{service?: string, host?: string, environment?: string}  $values  what the
     *                                                                                `{service}`, `{host}` and `{environment}` tokens expand to
     * @return list<MetricSummary>
     */
    public function for(array $scope, DateTimeInterface $start, DateTimeInterface $end, array $values = []): array
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

            $query = $this->expand((string) $signal['query'], $selector, $values);
            if ($query === null) {
                continue; // it needs a value this slice doesn't have (a trace with no host, …)
            }

            $summary = $this->resolve($signal, $query, $start, $end, $baselineStart);
            if ($summary !== null) {
                $out[] = $summary;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $signal
     */
    private function resolve(array $signal, string $query, DateTimeInterface $start, DateTimeInterface $end, DateTimeInterface $baselineStart): ?MetricSummary
    {
        try {
            $series = $this->connections->metrics()->queryRange(MetricQuery::raw($query), $start, $end);
        } catch (SourceException) {
            return null; // fail-open: a missing signal is one fewer tile.
        }

        $points = $this->points($series);

        // No signal here — don't render an empty tile. Unless the signal says
        // a flat zero is the answer (`keep_zero`): "the worker queue was empty"
        // is exactly what clears a server when a request was slow.
        if ($points === [] || (max(array_map('abs', $points)) === 0.0 && ($signal['keep_zero'] ?? false) !== true)) {
            return null;
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

        // Redis (and Memcached) keep a number as the bare number and hand it
        // back as a string, so what comes out of the cache is read, not trusted.
        $cached = $this->cache->store()->remember($key, $ttl, function () use ($query, $start, $end): ?float {
            try {
                $points = $this->points($this->connections->metrics()->queryRange(MetricQuery::raw($query), $start, $end));
            } catch (SourceException) {
                return null;
            }

            return $points === [] ? null : array_sum($points) / count($points);
        });

        return is_numeric($cached) ? (float) $cached : null;
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

    /**
     * Fill a signal's template. `{scope}` is the matcher list for the scope;
     * `{service}`, `{host}` and `{environment}` are the bare values, escaped for
     * a PromQL string, for exporters that label things their own way
     * (`node_load1{nodename="{host}"}`, a database's metrics by
     * `environment="{environment}"`). A template that uses a value the slice
     * doesn't have is skipped (null) rather than run unscoped.
     *
     * @param  array{service?: string, host?: string, environment?: string}  $values
     */
    private function expand(string $query, string $selector, array $values = []): ?string
    {
        foreach (['service', 'host', 'environment'] as $token) {
            if (! str_contains($query, '{'.$token.'}')) {
                continue;
            }

            $value = $values[$token] ?? '';
            if ($value === '') {
                return null;
            }

            $query = str_replace('{'.$token.'}', addcslashes($value, '"\\'), $query);
        }

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
