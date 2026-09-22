<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Pennant feature-flag checks by flag and result — which flags are hot, how
 * they resolve, and (the smell detector) checks against flags that have no
 * registered definition.
 */
final class FeatureChecks extends Panel
{
    public static function span(): int
    {
        return 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $rows = [];
        $unknown = [];
        $error = null;

        try {
            $checks = $this->metrics()->query(
                $this->metric('feature_checks_total')->increase($this->promDuration())->sumBy('feature', 'result'),
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

            foreach ($this->metrics()->query($this->metric('feature_unknown_total')->increase($this->promDuration())->sumBy('feature')) as $sample) {
                if ($sample->value >= 0.5) {
                    $unknown[] = ['feature' => $sample->labels['feature'] ?? '?', 'checks' => $sample->value];
                }
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $table = [];

        foreach (array_slice($rows, 0, 100) as $row) {
            $active = $row['results']['active'] ?? 0.0;
            $results = [];

            foreach ($row['results'] as $result => $count) {
                $results[] = $result.' · '.Format::count($count);
            }

            $table[] = [
                'feature' => Ui::cell($row['feature'], ['mono' => true]),
                'checks' => Ui::cell(Format::count($row['checks']), ['raw' => $row['checks'], 'mono' => true]),
                'active' => $row['checks'] > 0
                    ? Ui::cell(Format::percent($active / $row['checks']), ['raw' => $active / $row['checks'], 'mono' => true, 'bar' => $active / $row['checks']])
                    : Ui::cell('—', ['mono' => true]),
                'results' => Ui::cell(implode('  ', $results)),
            ];
        }

        $extra = [
            'subtitle' => 'Pennant checks by flag and result over the period',
            'span' => 2,
            'error' => $error,
            'empty' => 'No feature-flag checks in this period.',
        ];

        $columns = [
            Ui::col('feature', 'Feature'),
            Ui::num('checks', 'Checks'),
            Ui::num('active', 'Active'),
            Ui::col('results', 'Results'),
        ];

        $tablePayload = Ui::table('Feature flags', $columns, $table, $extra);

        if ($error !== null || $unknown === []) {
            return $tablePayload;
        }

        $flags = array_map(
            static fn (array $flag): string => $flag['feature'].' ('.Format::count($flag['checks']).')',
            $unknown,
        );

        // Checks against flags with no registered definition — the smell
        // detector — lead the panel as a warning above the table.
        return Ui::composite('Feature flags', [
            Ui::callout('Unregistered flags', 'Checks against unregistered flags (typo or stale flag): '.implode(', ', $flags), 'warn'),
            Ui::table('', $columns, $table, ['empty' => 'No registered feature-flag checks in this period.']),
        ], ['subtitle' => $extra['subtitle'], 'span' => 2]);
    }
}
