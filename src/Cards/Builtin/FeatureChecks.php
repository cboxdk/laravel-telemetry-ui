<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * Pennant feature-flag checks by flag and result — which flags are hot, how
 * they resolve, and (the smell detector) checks against flags that have no
 * registered definition.
 */
final class FeatureChecks extends Card
{
    public function render(): View
    {
        $rows = [];
        $unknown = [];
        $error = null;

        try {
            $checks = $this->metrics()->query(
                'sum by (feature, result) (increase('.$this->metric('feature_checks_total').'['.$this->promDuration().']))',
            );

            /** @var array<string, array{feature: string, checks: float, results: array<string, float>}> $features */
            $features = [];

            foreach ($checks as $sample) {
                if ($sample->value < 0.5) {
                    continue;
                }

                $feature = $sample->labels['feature'] ?? '?';
                $result = $sample->labels['result'] ?? '?';

                $row = $features[$feature] ?? ['feature' => $feature, 'checks' => 0.0, 'results' => []];
                $row['checks'] += $sample->value;
                $row['results'][$result] = ($row['results'][$result] ?? 0.0) + $sample->value;
                $features[$feature] = $row;
            }

            $rows = array_values($features);
            usort($rows, static fn (array $a, array $b): int => $b['checks'] <=> $a['checks']);

            foreach ($this->metrics()->query('sum by (feature) (increase('.$this->metric('feature_unknown_total').'['.$this->promDuration().']))') as $sample) {
                if ($sample->value >= 0.5) {
                    $unknown[] = ['feature' => $sample->labels['feature'] ?? '?', 'checks' => $sample->value];
                }
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.feature-checks';

        return view($view, [
            'rows' => array_slice($rows, 0, 100),
            'unknown' => $unknown,
            'error' => $error,
        ]);
    }
}
