<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors\Prometheus;

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\MetricsSource;
use Cbox\TelemetryUi\Queries\Compilers\PromqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Results\DataPoint;
use Cbox\TelemetryUi\Queries\Results\Sample;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use DateTimeInterface;

/**
 * Prometheus HTTP API driver. Also the base for Mimir, which serves the
 * same API under a path prefix.
 */
class PrometheusSource implements MetricsSource
{
    /**
     * Target number of points per series when deriving a range-query step.
     */
    private const TARGET_POINTS = 250;

    public function __construct(
        protected readonly ApiClient $client,
        protected readonly string $prefix = '',
    ) {}

    public function query(MetricQuery $query, ?DateTimeInterface $at = null): array
    {
        $params = ['query' => (new PromqlCompiler)->compile($query)];

        if ($at !== null) {
            $params['time'] = $at->getTimestamp();
        }

        $data = $this->data($this->path('/api/v1/query'), $params);

        return $this->parseVector($data);
    }

    public function queryRange(
        MetricQuery $query,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?int $step = null,
    ): array {
        $step ??= $this->deriveStep($start, $end);

        $data = $this->data($this->path('/api/v1/query_range'), [
            'query' => (new PromqlCompiler)->compile($query),
            'start' => $start->getTimestamp(),
            'end' => $end->getTimestamp(),
            'step' => $step,
        ]);

        return $this->parseMatrix($data);
    }

    public function labelValues(
        string $label,
        ?string $match = null,
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null,
    ): array {
        $params = [];

        if ($match !== null) {
            $params['match[]'] = $match;
        }

        if ($start !== null) {
            $params['start'] = $start->getTimestamp();
        }

        if ($end !== null) {
            $params['end'] = $end->getTimestamp();
        }

        $response = $this->client->get($this->path('/api/v1/label/'.rawurlencode($label).'/values'), $params);

        $values = $response['data'] ?? [];

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): ?string => is_string($value) ? $value : null,
            $values,
        ), static fn (?string $value): bool => $value !== null));
    }

    protected function path(string $path): string
    {
        return ($this->prefix === '' ? '' : '/'.trim($this->prefix, '/')).$path;
    }

    /**
     * Fetch and unwrap a Prometheus API envelope ({status, data}).
     *
     * @param  array<string, int|float|string>  $params
     * @return array<string, mixed>
     */
    protected function data(string $path, array $params): array
    {
        $response = $this->client->get($path, $params);

        if (($response['status'] ?? null) !== 'success') {
            throw SourceException::unexpectedPayload($path, (string) ($response['error'] ?? 'status is not success'));
        }

        $data = $response['data'] ?? null;

        if (! is_array($data)) {
            throw SourceException::unexpectedPayload($path, 'missing data section');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<Sample>
     */
    private function parseVector(array $data): array
    {
        $result = is_array($data['result'] ?? null) ? $data['result'] : [];

        if (($data['resultType'] ?? null) === 'scalar') {
            // A scalar result is a bare [ts, "value"] pair, not a list of them.
            if (count($result) < 2) {
                return [];
            }

            $value = $this->toFiniteFloat($result[1]);

            return $value === null ? [] : [new Sample([], (float) $result[0], $value)];
        }

        $samples = [];

        foreach ($result as $entry) {
            if (! is_array($entry) || ! isset($entry['value']) || ! is_array($entry['value'])) {
                continue;
            }

            // Prometheus serializes NaN/±Inf as the strings "NaN"/"+Inf"; a
            // blind (float) cast would turn them into a misleading 0.0, so drop
            // the sample instead of rendering a false zero.
            $value = $this->toFiniteFloat($entry['value'][1] ?? null);

            if ($value === null) {
                continue;
            }

            $samples[] = new Sample(
                labels: $this->labels($entry),
                timestamp: (float) $entry['value'][0],
                value: $value,
            );
        }

        return $samples;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<TimeSeries>
     */
    private function parseMatrix(array $data): array
    {
        $result = is_array($data['result'] ?? null) ? $data['result'] : [];

        $series = [];

        foreach ($result as $entry) {
            if (! is_array($entry) || ! is_array($entry['values'] ?? null)) {
                continue;
            }

            $points = [];

            foreach ($entry['values'] as $value) {
                if (! is_array($value) || count($value) < 2) {
                    continue;
                }

                $point = $this->toFiniteFloat($value[1]);

                if ($point === null) {
                    continue;
                }

                $points[] = new DataPoint((float) $value[0], $point);
            }

            $series[] = new TimeSeries(labels: $this->labels($entry), points: $points);
        }

        return $series;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @return array<string, string>
     */
    private function labels(array $entry): array
    {
        $metric = is_array($entry['metric'] ?? null) ? $entry['metric'] : [];

        $labels = [];

        foreach ($metric as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $labels[$key] = $value;
            }
        }

        return $labels;
    }

    /**
     * Coerce a Prometheus sample value to a finite float, or null when it is
     * missing or a non-finite marker ("NaN", "+Inf", "-Inf") that must not be
     * rendered as a real zero. `is_numeric` already rejects those markers.
     */
    private function toFiniteFloat(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return is_finite((float) $raw) ? (float) $raw : null;
        }

        if (is_string($raw) && is_numeric($raw)) {
            $value = (float) $raw;

            return is_finite($value) ? $value : null;
        }

        return null;
    }

    private function deriveStep(DateTimeInterface $start, DateTimeInterface $end): int
    {
        $range = max(1, $end->getTimestamp() - $start->getTimestamp());

        return max(15, (int) ceil($range / self::TARGET_POINTS));
    }
}
