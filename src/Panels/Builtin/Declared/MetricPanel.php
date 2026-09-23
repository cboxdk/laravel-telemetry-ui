<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Declared;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\TelemetryUiManager;

/**
 * The panel behind {@see TelemetryUiManager::metricPanel()}: one chart, built
 * from a declared spec instead of a class. Which spec it is comes from the
 * request (`_panel`), so one class serves every declared panel — including
 * metrics from a sidecar written in any language, as long as a
 * Prometheus-compatible backend holds them.
 *
 * @phpstan-import-type DeclaredPanel from TelemetryUiManager
 */
final class MetricPanel extends Panel
{
    public function data(): array
    {
        $spec = $this->spec();

        if ($spec === null) {
            return $this->chartCard('Panel', error: 'This panel is no longer declared.');
        }

        return $this->promChart(
            $spec['title'],
            $this->query($spec),
            subtitle: $spec['subtitle'],
            seriesLabel: $spec['by'],
            type: $spec['type'],
            unit: $spec['unit'] ?? 'number',
            span: $spec['span'],
            stat: $spec['stat'],
        );
    }

    /**
     * A gauge reads as itself, a counter as per-minute throughput, a histogram
     * as a quantile.
     *
     * @param  DeclaredPanel  $spec
     */
    private function query(array $spec): MetricQuery
    {
        $matchers = implode(',', array_map(
            static fn (string $key, string $value): string => sprintf('%s="%s"', $key, addslashes($value)),
            array_keys($spec['where']),
            array_values($spec['where']),
        ));

        $metric = $this->metric($spec['metric'], $matchers);
        $by = $spec['by'] !== null ? [$spec['by']] : [];

        if ($spec['quantile'] !== null) {
            return $metric->quantile($spec['quantile'], $this->rateWindow(), ...$by);
        }

        return $spec['rate']
            ? $metric->rate($this->rateWindow())->sumBy(...$by)->times(60)
            : $metric->avgBy(...$by);
    }

    /**
     * @return DeclaredPanel|null
     */
    private function spec(): ?array
    {
        return app(TelemetryUiManager::class)->declaredPanel($this->scope->param('_panel'))['spec'] ?? null;
    }
}
