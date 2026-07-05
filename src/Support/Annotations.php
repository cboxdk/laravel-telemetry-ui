<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Source of chart annotations. Deploy markers (and any configured marker
 * events) are emitted by cboxdk/laravel-telemetry into the logs backend
 * (Loki); this reads them back so every chart can show "regressions map to
 * deploys" the way the bundled Grafana dashboards do.
 *
 * Markers are sparse, so a single wide lookback query per scope is cached
 * and every card filters it to its own range in memory — N charts on a page
 * cost one Loki query, not N.
 */
final readonly class Annotations
{
    public function __construct(
        private ConnectionManager $connections,
        private CacheFactory $cache,
        private Config $config,
        private int $ttl = 30,
        private int $lookbackDays = 30,
    ) {}

    /**
     * Markers within [$start, $end] for the given scope, newest first.
     *
     * @param  array<string, string>  $streamMatchers  e.g. service_name / deployment_environment_name
     * @return list<Annotation>
     */
    public function between(DateTimeImmutable $start, DateTimeImmutable $end, array $streamMatchers = []): array
    {
        $all = $this->lookback($streamMatchers);

        $from = $start->getTimestamp() * 1000.0;
        $to = $end->getTimestamp() * 1000.0;

        return array_values(array_filter(
            $all,
            static fn (Annotation $annotation): bool => $annotation->timestampMs >= $from && $annotation->timestampMs <= $to,
        ));
    }

    /**
     * All markers within the lookback window for a scope (cached).
     *
     * @param  array<string, string>  $streamMatchers
     * @return list<Annotation>
     */
    public function lookback(array $streamMatchers = []): array
    {
        if (! (bool) $this->config->get('telemetry-ui.annotations.enabled', true)) {
            return [];
        }

        ksort($streamMatchers);

        $key = 'telemetry-ui:annotations:'.md5(serialize($streamMatchers));

        // Cache primitive rows, not Annotation objects — file/database/redis
        // cache stores serialize, and cross-request unserialize of a package
        // class can yield __PHP_Incomplete_Class. Rehydrate after reading.
        /** @var list<array{ts: float, label: string, notes: string|null, kind: string, traceId: string|null, color: string}> $rows */
        $rows = $this->cache->store()->remember($key, $this->ttl, fn (): array => $this->fetch($streamMatchers));

        return array_map(
            static fn (array $row): Annotation => new Annotation(
                timestampMs: $row['ts'],
                label: $row['label'],
                notes: $row['notes'],
                kind: $row['kind'],
                traceId: $row['traceId'],
                color: $row['color'],
            ),
            $rows,
        );
    }

    /**
     * @param  array<string, string>  $streamMatchers
     * @return list<array{ts: float, label: string, notes: string|null, kind: string, traceId: string|null, color: string}>
     */
    private function fetch(array $streamMatchers): array
    {
        /** @var array<string, array{event: string, label: string, color: string, notes_label?: string, id_label?: string}> $markers */
        $markers = (array) $this->config->get('telemetry-ui.annotations.markers', []);

        if ($markers === []) {
            return [];
        }

        // Map event name → marker, and match ALL markers in a single Loki
        // query with a regex line filter — markers are sparse, so one query
        // over the lookback beats one query per marker type (6+ round trips).
        $byEvent = [];
        foreach ($markers as $marker) {
            $event = $marker['event'] ?? '';
            if ($event !== '') {
                $byEvent[$event] = $marker;
            }
        }

        if ($byEvent === []) {
            return [];
        }

        $selector = $this->selector($streamMatchers);
        $end = new DateTimeImmutable;
        $start = $end->modify('-'.$this->lookbackDays.' days');

        $pattern = implode('|', array_map(
            static fn (string $event): string => preg_quote($event, '/'),
            array_keys($byEvent),
        ));

        try {
            $entries = $this->connections->logs()->query(
                $selector.' |~ "'.addcslashes($pattern, '"\\').'"',
                $start,
                $end,
                limit: 500,
            );
        } catch (SourceException) {
            return [];
        }

        $annotations = [];

        foreach ($entries as $entry) {
            // Classify by the exact event name; ignore incidental matches.
            $marker = $byEvent[trim($entry->line)] ?? null;

            if ($marker === null) {
                continue;
            }

            $id = $entry->labels[$marker['id_label'] ?? 'deployment_id'] ?? null;
            $notes = $entry->labels[$marker['notes_label'] ?? 'deployment_notes'] ?? null;

            $annotations[] = [
                'ts' => $entry->timestampNano / 1_000_000,
                'label' => $id !== null && $id !== '' ? ($marker['label'] ?? 'Deploy').' '.$id : ($marker['label'] ?? 'Marker'),
                'notes' => $notes !== null && $notes !== '' ? $notes : null,
                'kind' => trim($entry->line),
                'traceId' => $entry->labels['trace_id'] ?? null,
                'color' => $marker['color'] ?? '#c084fc',
            ];
        }

        usort($annotations, static fn (array $a, array $b): int => $b['ts'] <=> $a['ts']);

        return $annotations;
    }

    /**
     * @param  array<string, string>  $streamMatchers
     */
    private function selector(array $streamMatchers): string
    {
        $parts = [];

        foreach ($streamMatchers as $label => $value) {
            if ($value !== '') {
                $parts[] = $label.'="'.addcslashes($value, '"\\').'"';
            }
        }

        if ($parts === []) {
            $parts[] = 'service_name=~".+"';
        }

        return '{'.implode(',', $parts).'}';
    }
}
