<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * Shared shape for "name → outcome counters + duration histogram" tables
 * (commands, scheduled tasks).
 */
abstract class BreakdownTable extends Card
{
    /**
     * @return array{title: string, keyLabel: string, keyColumn: string, durationMetric: string, outcomes: array<string, string>}
     */
    abstract protected function spec(): array;

    public function render(): View
    {
        $spec = $this->spec();
        $p = $this->promDuration();
        $key = $spec['keyLabel'];

        $rows = [];
        $error = null;

        try {
            foreach ($spec['outcomes'] as $outcome => $metricName) {
                $samples = $this->metrics()->query(
                    'sum by ('.$key.') (increase('.$this->metric($metricName).'['.$p.']))',
                );

                foreach ($samples as $sample) {
                    $name = $sample->labels[$key] ?? '?';

                    $rows[$name] ??= [
                        'name' => $name,
                        'outcomes' => array_fill_keys(array_keys($spec['outcomes']), 0.0),
                        'time' => 0.0, 'count' => 0.0, 'p95' => null,
                    ];

                    $rows[$name]['outcomes'][$outcome] += $sample->value;
                }
            }

            foreach ($this->metrics()->query('sum by ('.$key.') (increase('.$this->metric($spec['durationMetric'].'_sum').'['.$p.']))') as $sample) {
                $name = $sample->labels[$key] ?? '?';

                if (isset($rows[$name])) {
                    $rows[$name]['time'] = $sample->value;
                }
            }

            foreach ($this->metrics()->query('sum by ('.$key.') (increase('.$this->metric($spec['durationMetric'].'_count').'['.$p.']))') as $sample) {
                $name = $sample->labels[$key] ?? '?';

                if (isset($rows[$name])) {
                    $rows[$name]['count'] = $sample->value;
                }
            }

            foreach ($this->metrics()->query('histogram_quantile(0.95, sum by ('.$key.', le) (rate('.$this->metric($spec['durationMetric'].'_bucket').'['.$p.'])))') as $sample) {
                $name = $sample->labels[$key] ?? '?';

                if (isset($rows[$name]) && ! is_nan($sample->value)) {
                    $rows[$name]['p95'] = $sample->value;
                }
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        // increase() extrapolation leaves near-zero ghosts at period edges.
        $rows = array_filter($rows, static fn (array $row): bool => array_sum($row['outcomes']) >= 0.5);

        usort($rows, static fn (array $a, array $b): int => array_sum($b['outcomes']) <=> array_sum($a['outcomes']));

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.breakdown-table';

        return view($view, [
            'title' => $spec['title'],
            'keyColumn' => $spec['keyColumn'],
            'outcomeColumns' => array_keys($spec['outcomes']),
            'rows' => array_slice($rows, 0, 100),
            'error' => $error,
        ]);
    }
}
