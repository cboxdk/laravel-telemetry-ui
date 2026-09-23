<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Results\DataPoint;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Cbox\TelemetryUi\Support\Format;

/**
 * Shared shape for the system gauge charts (cboxdk/system-metrics).
 */
abstract class SystemCharts extends Panel
{
    /**
     * `subtitle` explains the chart; `stats` names the series whose latest
     * value leads the card (all series, up to four, when omitted); `rate`
     * marks a per-second series (headline shown as "/s").
     *
     * @return array{title: string, query: MetricQuery, label: string|null, unit: string, type: string, subtitle?: string, stats?: list<string>, rate?: bool}
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
            stats: $this->latest($range, $spec),
            type: $spec['type'],
            unit: $spec['unit'],
            subtitle: $spec['subtitle'] ?? null,
        );
    }

    /**
     * The newest value of each headline series — "now", next to the trend.
     *
     * @param  list<TimeSeries>  $range
     * @param  array{label: string|null, unit: string, stats?: list<string>, rate?: bool}  $spec
     * @return list<array{label: string, value: string, tone: string|null}>
     */
    private function latest(array $range, array $spec): array
    {
        $byName = [];

        foreach ($range as $series) {
            $points = array_values(array_filter($series->points, static fn (DataPoint $p): bool => ! is_nan($p->value)));

            if ($points !== []) {
                $byName[$series->name($spec['label'])] = $points[count($points) - 1]->value;
            }
        }

        $names = $spec['stats'] ?? array_slice(array_keys($byName), 0, 4);
        $stats = [];

        foreach ($names as $name) {
            if (! isset($byName[$name])) {
                continue;
            }

            $value = $byName[$name];
            $formatted = match ($spec['unit']) {
                'bytes' => self::bytes($value).(($spec['rate'] ?? false) ? '/s' : ''),
                default => number_format($value, $value < 10 ? 2 : 1),
            };

            $stats[] = $this->stat(ucfirst($name), $formatted);
        }

        return $stats;
    }

    /** Format::bytes plus TB — disks are the one place terabytes show up. */
    private static function bytes(float $bytes): string
    {
        return abs($bytes) >= 1_099_511_627_776
            ? rtrim(rtrim(number_format($bytes / 1_099_511_627_776, 1), '0'), '.').' TB'
            : Format::bytes($bytes);
    }
}
