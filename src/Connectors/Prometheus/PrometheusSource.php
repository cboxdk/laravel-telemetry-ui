<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors\Prometheus;

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\MetricsSource;
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

    public function query(string $promql, ?DateTimeInterface $at = null): array
    {
        $params = ['query' => $promql];

        if ($at !== null) {
            $params['time'] = $at->getTimestamp();
        }

        $data = $this->data($this->path('/api/v1/query'), $params);

        return $this->parseVector($data);
    }

    public function queryRange(
        string $promql,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?int $step = null,
    ): array {
        $step ??= $this->deriveStep($start, $end);

        $data = $this->data($this->path('/api/v1/query_range'), [
            'query' => $promql,
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
            /** @var array{0: int|float, 1: string} $scalar */
            $scalar = $data['result'];

            return [new Sample([], (float) $scalar[0], (float) $scalar[1])];
        }

        $samples = [];

        foreach ($result as $entry) {
            if (! is_array($entry) || ! isset($entry['value']) || ! is_array($entry['value'])) {
                continue;
            }

            $samples[] = new Sample(
                labels: $this->labels($entry),
                timestamp: (float) $entry['value'][0],
                value: (float) $entry['value'][1],
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

                $points[] = new DataPoint((float) $value[0], (float) $value[1]);
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

    private function deriveStep(DateTimeInterface $start, DateTimeInterface $end): int
    {
        $range = max(1, $end->getTimestamp() - $start->getTimestamp());

        return max(15, (int) ceil($range / self::TARGET_POINTS));
    }
}
