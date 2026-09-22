<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;

/**
 * Shared shape for the system gauge charts (cboxdk/system-metrics).
 */
abstract class SystemCharts extends Panel
{
    /**
     * @return array{title: string, query: MetricQuery, label: string|null, unit: string, type: string}
     */
    abstract protected function spec(): array;

    public function data(): array
    {
        $spec = $this->spec();

        [$start, $end] = $this->range();

        try {
            $range = $this->metrics()->queryRange($spec['query'], $start, $end);
        } catch (SourceException $exception) {
            return $this->chartCard($spec['title'], error: $exception->getMessage());
        }

        return $this->chartCard(
            title: $spec['title'],
            series: $this->toChartSeries($range, $spec['label']),
            type: $spec['type'],
            unit: $spec['unit'],
        );
    }
}
