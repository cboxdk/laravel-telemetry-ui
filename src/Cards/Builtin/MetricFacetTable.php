<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * Shared shape for "counter grouped by one or more labels → count, per-minute
 * rate and share" tables. Turns a single-series overview chart into a real
 * breakdown a reader can scan (which operation / preset / form / collection
 * dominates), without needing per-item spans.
 *
 * Subclasses declare the metric and the label→column map; everything else —
 * summing, share, sorting, empty/error states — is handled here.
 */
abstract class MetricFacetTable extends Card
{
    /**
     * @return array{title: string, metric: string, keys: array<string, string>, valueColumn: string}
     */
    abstract protected function spec(): array;

    public function render(): View
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

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.metric-facet-table';

        return view($view, [
            'title' => $spec['title'],
            'keyColumns' => array_values($spec['keys']),
            'valueColumn' => $spec['valueColumn'],
            'rows' => array_slice($rows, 0, 100),
            'error' => $error,
        ]);
    }
}
