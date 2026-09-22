<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Shared shape for "counter grouped by one or more labels → count, per-minute
 * rate and share" tables. Turns a single-series overview chart into a real
 * breakdown a reader can scan (which operation / preset / form / collection
 * dominates), without needing per-item spans.
 *
 * Subclasses declare the metric and the label→column map; everything else —
 * summing, share, sorting, empty/error states — is handled here.
 */
abstract class MetricFacetTable extends Panel
{
    /**
     * @return array{title: string, metric: string, keys: array<string, string>, valueColumn: string}
     */
    abstract protected function spec(): array;

    public static function span(): int
    {
        return 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $spec = $this->spec();
        $labels = array_keys($spec['keys']);

        $rows = [];
        $error = null;

        try {
            $samples = $this->metrics()->query(
                $this->metric($spec['metric'])->increase($this->promDuration())->sumBy(...$labels),
            );

            foreach ($samples as $sample) {
                $keyVals = [];
                foreach ($labels as $label) {
                    $keyVals[$label] = $sample->labels[$label] ?? '?';
                }
                $rowKey = implode("\x1f", $keyVals);

                $rows[$rowKey] ??= ['keys' => $keyVals, 'count' => 0.0];
                $rows[$rowKey]['count'] += $sample->value;
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        // increase() extrapolation leaves near-zero ghosts at period edges.
        $rows = array_filter($rows, static fn (array $row): bool => $row['count'] >= 0.5);

        $total = array_sum(array_column($rows, 'count'));
        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        foreach ($rows as &$row) {
            $row['share'] = $total > 0.0 ? $row['count'] / $total : 0.0;
        }
        unset($row);

        $columns = [];

        foreach (array_values($spec['keys']) as $i => $label) {
            $columns[] = Ui::col('k'.$i, $label);
        }

        $columns[] = Ui::num('count', $spec['valueColumn']);
        $columns[] = Ui::num('share', 'Share');

        $table = [];

        foreach (array_slice($rows, 0, 100) as $row) {
            $cells = [];

            foreach (array_values($row['keys']) as $i => $value) {
                $cells['k'.$i] = Ui::cell($value);
            }

            $cells['count'] = Ui::cell(Format::count($row['count']), ['raw' => $row['count'], 'mono' => true]);
            $cells['share'] = Ui::cell(Format::percent($row['share']), ['raw' => $row['share'], 'mono' => true, 'bar' => $row['share']]);

            $table[] = $cells;
        }

        return Ui::table($spec['title'], $columns, $table, [
            'span' => 2,
            'error' => $error,
            'empty' => 'No data in this period.',
        ]);
    }
}
