<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Statamic;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Content changes broken down by type × action (entry saved, term deleted…).
 */
final class ContentChanges extends Panel
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
        $metric = $this->metric('statamic_content_changes_total');

        $rows = [];
        $error = null;

        try {
            foreach ($this->metrics()->query($metric->increase($this->promDuration())->sumBy('type', 'action')) as $sample) {
                if ($sample->value < 0.5) {
                    continue;
                }

                $rows[] = [
                    'type' => $sample->labels['type'] ?? '?',
                    'action' => $sample->labels['action'] ?? '?',
                    'count' => $sample->value,
                ];
            }

            usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $table = array_map(static fn (array $row): array => [
            'type' => Ui::cell($row['type']),
            'action' => Ui::cell($row['action'], ['badge' => $row['action']]),
            'count' => Ui::cell(Format::count($row['count']), ['raw' => $row['count'], 'mono' => true]),
        ], array_slice($rows, 0, 100));

        return Ui::table('Content changes', [
            Ui::col('type', 'Type'),
            Ui::col('action', 'Action'),
            Ui::num('count', 'Count'),
        ], $table, [
            'span' => 2,
            'error' => $error,
            'empty' => 'No content changes in this period.',
        ]);
    }
}
