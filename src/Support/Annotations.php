<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\LabelMatcher;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
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
     * @param  LogQuery|null  $selector  a log selector already scoped to the
     *                                   viewer's service/env (from Card::logSelector());
     *                                   null matches any service
     * @return list<Annotation>
     */
    public function between(DateTimeImmutable $start, DateTimeImmutable $end, ?LogQuery $selector = null): array
    {
        $all = $this->lookback($selector);

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
     * @return list<Annotation>
     */
    public function lookback(?LogQuery $selector = null): array
    {
        $selector ??= LogQuery::stream(new LabelMatcher('service_name', MatchOp::Re, '.+'));

        if (! (bool) $this->config->get('telemetry-ui.annotations.enabled', true)) {
            return [];
        }

        // v2: cluster fields (count/end/hosts) joined the row shape.
        $key = 'telemetry-ui:annotations:v2:'.md5($selector->key());

        // Cache primitive rows, not Annotation objects — file/database/redis
        // cache stores serialize, and cross-request unserialize of a package
        // class can yield __PHP_Incomplete_Class. Rehydrate after reading.
        /** @var list<array{ts: float, label: string, notes: string|null, kind: string, traceId: string|null, color: string, count: int, end: float|null, hosts: list<string>}> $rows */
        $rows = $this->cache->store()->remember($key, $this->ttl, fn (): array => $this->fetch($selector));

        return array_map(
            static fn (array $row): Annotation => new Annotation(
                timestampMs: $row['ts'],
                label: $row['label'],
                notes: $row['notes'],
                kind: $row['kind'],
                traceId: $row['traceId'],
                color: $row['color'],
                count: $row['count'] ?? 1,
                endMs: $row['end'] ?? null,
                hosts: $row['hosts'] ?? [],
            ),
            $rows,
        );
    }

    /**
     * @return list<array{ts: float, label: string, notes: string|null, kind: string, traceId: string|null, color: string, count: int, end: float|null, hosts: list<string>}>
     */
    private function fetch(LogQuery $selector): array
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

        $end = new DateTimeImmutable;
        $start = $end->modify('-'.$this->lookbackDays.' days');

        $pattern = implode('|', array_map(
            static fn (string $event): string => preg_quote($event, '/'),
            array_keys($byEvent),
        ));

        try {
            $entries = $this->connections->logs()->query(
                $selector->lineMatches($pattern),
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
                'host' => $entry->labels['host_name'] ?? null,
            ];
        }

        usort($annotations, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);

        $clustered = $this->cluster($annotations);

        usort($clustered, static fn (array $a, array $b): int => $b['ts'] <=> $a['ts']);

        return $clustered;
    }

    /**
     * A horizontal rollout emits the SAME marker (kind + label, e.g.
     * "Deploy v2.4.1") from every host within minutes — 200 servers must
     * not become 200 lines on every chart. Fold same-kind+label events
     * whose gaps stay under the window into one cluster carrying the
     * count, the covered hosts and the rollout span (first → last).
     *
     * @param  list<array{ts: float, label: string, notes: string|null, kind: string, traceId: string|null, color: string, host: string|null}>  $annotations  sorted ascending by ts
     * @return list<array{ts: float, label: string, notes: string|null, kind: string, traceId: string|null, color: string, count: int, end: float|null, hosts: list<string>}>
     */
    private function cluster(array $annotations, float $gapMs = 900_000): array
    {
        /** @var array<string, array{ts: float, label: string, notes: string|null, kind: string, traceId: string|null, color: string, count: int, end: float, hosts: list<string>}> $open */
        $open = [];
        $out = [];

        /** @param array{ts: float, label: string, notes: string|null, kind: string, traceId: string|null, color: string, count: int, end: float, hosts: list<string>} $cluster */
        $flush = static function (array $cluster) use (&$out): void {
            $out[] = [
                'ts' => $cluster['ts'],
                'label' => $cluster['label'],
                'notes' => $cluster['notes'],
                'kind' => $cluster['kind'],
                'traceId' => $cluster['traceId'],
                'color' => $cluster['color'],
                'count' => $cluster['count'],
                'end' => $cluster['end'] > $cluster['ts'] ? $cluster['end'] : null,
                'hosts' => array_values(array_unique($cluster['hosts'])),
            ];
        };

        foreach ($annotations as $annotation) {
            $key = $annotation['kind'].'|'.$annotation['label'];
            $host = $annotation['host'];
            unset($annotation['host']);

            $current = $open[$key] ?? null;

            if ($current !== null && $gapMs >= $annotation['ts'] - $current['end']) {
                $current['count']++;
                $current['end'] = $annotation['ts'];
                $current['notes'] ??= $annotation['notes'];
                $current['traceId'] ??= $annotation['traceId'];
                if ($host !== null && $host !== '') {
                    $current['hosts'][] = $host;
                }
                $open[$key] = $current;

                continue;
            }

            if ($current !== null) {
                $flush($current);
            }

            $open[$key] = [
                ...$annotation,
                'count' => 1,
                'end' => $annotation['ts'],
                'hosts' => $host !== null && $host !== '' ? [$host] : [],
            ];
        }

        foreach ($open as $cluster) {
            $flush($cluster);
        }

        return $out;
    }
}
