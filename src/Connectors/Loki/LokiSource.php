<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors\Loki;

use Cbox\TelemetryUi\Connectors\ApiClient;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\LogsSource;
use Cbox\TelemetryUi\Queries\Results\LogEntry;
use DateTimeInterface;

/**
 * Grafana Loki driver (LogQL over the HTTP query API).
 */
final readonly class LokiSource implements LogsSource
{
    public function __construct(private ApiClient $client) {}

    public function query(
        string $logql,
        DateTimeInterface $start,
        DateTimeInterface $end,
        int $limit = 100,
    ): array {
        $path = '/loki/api/v1/query_range';

        $response = $this->client->get($path, [
            'query' => $logql,
            'start' => $start->getTimestamp() * 1_000_000_000,
            'end' => $end->getTimestamp() * 1_000_000_000,
            'limit' => $limit,
            'direction' => 'backward',
        ]);

        if (($response['status'] ?? null) !== 'success') {
            throw SourceException::unexpectedPayload($path, 'status is not success');
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        if (($data['resultType'] ?? 'streams') !== 'streams') {
            throw SourceException::unexpectedPayload($path, 'expected a streams result; metric LogQL queries are not supported here');
        }

        $result = is_array($data['result'] ?? null) ? $data['result'] : [];

        $entries = [];

        foreach ($result as $stream) {
            if (! is_array($stream)) {
                continue;
            }

            $labels = $this->labels($stream);

            $values = is_array($stream['values'] ?? null) ? $stream['values'] : [];

            foreach ($values as $value) {
                if (! is_array($value) || count($value) < 2) {
                    continue;
                }

                $entries[] = new LogEntry(
                    timestampNano: (int) $value[0],
                    line: (string) $value[1],
                    labels: $labels,
                );
            }
        }

        usort($entries, static fn (LogEntry $a, LogEntry $b): int => $a->timestampNano <=> $b->timestampNano);

        return $entries;
    }

    public function labelValues(
        string $label,
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null,
    ): array {
        $params = [];

        if ($start !== null) {
            $params['start'] = $start->getTimestamp() * 1_000_000_000;
        }

        if ($end !== null) {
            $params['end'] = $end->getTimestamp() * 1_000_000_000;
        }

        $response = $this->client->get('/loki/api/v1/label/'.rawurlencode($label).'/values', $params);

        $values = $response['data'] ?? [];

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): ?string => is_string($value) ? $value : null,
            $values,
        ), static fn (?string $value): bool => $value !== null));
    }

    /**
     * @param  array<array-key, mixed>  $stream
     * @return array<string, string>
     */
    private function labels(array $stream): array
    {
        $raw = is_array($stream['stream'] ?? null) ? $stream['stream'] : [];

        $labels = [];

        foreach ($raw as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $labels[$key] = $value;
            }
        }

        return $labels;
    }
}
