<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Analysis;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\AnnotationWriter;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Proactive analysis: detects a laravel_version that's live in the metrics but
 * has no version annotation yet, and emits one — so an un-announced deploy
 * still lands on every chart. Stateless: it dedups against the version
 * annotations already in Loki (the store is the state), never a local table.
 */
final readonly class VersionScanner
{
    public function __construct(
        private ConnectionManager $connections,
        private AnnotationWriter $writer,
        private Config $config,
    ) {}

    /**
     * @return array{emitted: list<string>, live: int}
     */
    public function scan(): array
    {
        $marker = (string) $this->config->get('telemetry-ui.annotations.auto_version.marker', 'version');
        $metric = (string) $this->config->get('telemetry-ui.annotations.auto_version.metric', 'system_cpu_utilization_ratio');
        $days = max(1, (int) $this->config->get('telemetry-ui.annotations.auto_version.lookback_days', 30));

        $live = $this->liveVersions($metric);

        if ($live === []) {
            return ['emitted' => [], 'live' => 0];
        }

        // If we can't read what's already annotated, do NOT emit — better to
        // miss one than to spam a duplicate every scan.
        $seen = $this->annotatedVersions($marker, $days);
        if ($seen === null) {
            return ['emitted' => [], 'live' => count($live)];
        }

        $emitted = [];
        foreach ($live as $version => $service) {
            if (in_array($version, $seen, true)) {
                continue;
            }

            if ($this->writer->write($marker, $version, "First seen in production ({$service})")) {
                $emitted[] = $version;
            }
            $seen[] = $version; // guard against double-emit within this run
        }

        return ['emitted' => $emitted, 'live' => count($live)];
    }

    /**
     * Currently-reporting versions → the service they were last seen on.
     *
     * @return array<string, string>
     */
    private function liveVersions(string $metric): array
    {
        try {
            $samples = $this->connections->metrics()->query(
                'count by (laravel_version, service_name) ('.$metric.')',
            );
        } catch (SourceException) {
            return [];
        }

        $versions = [];
        foreach ($samples as $sample) {
            $version = $sample->labels['laravel_version'] ?? '';
            if ($version !== '') {
                $versions[$version] = $sample->labels['service_name'] ?? '';
            }
        }

        return $versions;
    }

    /**
     * Version ids that already carry an annotation, or null if we couldn't
     * read them (so the caller can refuse to emit rather than duplicate).
     *
     * @return list<string>|null
     */
    private function annotatedVersions(string $marker, int $days): ?array
    {
        /** @var array<string, mixed>|null $config */
        $config = $this->config->get('telemetry-ui.annotations.markers.'.$marker);

        if (! is_array($config) || ! is_string($config['event'] ?? null)) {
            return [];
        }

        $idLabel = is_string($config['id_label'] ?? null) ? $config['id_label'] : 'version_id';

        try {
            $entries = $this->connections->logs()->query(
                '{event="'.$config['event'].'"}',
                new DateTimeImmutable('-'.$days.' days'),
                new DateTimeImmutable,
                limit: 1000,
            );
        } catch (SourceException) {
            return null;
        }

        $seen = [];
        foreach ($entries as $entry) {
            $id = $entry->labels[$idLabel] ?? '';
            if ($id !== '') {
                $seen[] = $id;
            }
        }

        return array_values(array_unique($seen));
    }
}
