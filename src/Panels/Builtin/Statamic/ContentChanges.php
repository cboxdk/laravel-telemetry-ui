<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Statamic;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Support\Format;

/**
 * Content changes over time, stacked by what changed (entries, assets,
 * terms, navigation…) — when editing happens and how much. The breakdown
 * table beside it splits the same counter by type × action; the audit log
 * below lists the changes themselves.
 */
final class ContentChanges extends Panel
{
    /** Types charted as their own series; the rest fold into "other". */
    private const SERIES = 6;

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        [$start, $end] = $this->range();
        $metric = $this->metric('statamic_content_changes_total');

        try {
            $byTypeAction = $this->metrics()->query($metric->increase($this->promDuration())->sumBy('type', 'action'));
            $range = $this->metrics()->queryRange($metric->rate($this->rateWindow())->sumBy('type')->times(60), $start, $end);
        } catch (SourceException $exception) {
            return $this->chartCard('Content changes', error: $exception->getMessage());
        }

        $totals = [];
        $deleted = 0.0;
        foreach ($byTypeAction as $sample) {
            $type = $sample->labels['type'] ?? '?';
            $totals[$type] = ($totals[$type] ?? 0.0) + $sample->value;
            $deleted += ($sample->labels['action'] ?? '') === 'deleted' ? $sample->value : 0.0;
        }
        arsort($totals);
        $top = array_slice(array_keys($totals), 0, self::SERIES);

        $series = [];
        foreach ($range as $timeSeries) {
            $type = $timeSeries->labels['type'] ?? '?';
            if (in_array($type, $top, true)) {
                $series[] = ['name' => $type, 'data' => $timeSeries->toChartData()];
            }
        }
        usort($series, static fn (array $a, array $b): int => array_search($a['name'], $top, true) <=> array_search($b['name'], $top, true));

        $total = array_sum($totals);

        return $this->chartCard(
            title: 'Content changes',
            series: $series,
            stats: [
                $this->stat('Changes', Format::count($total), $total > 0 ? 'ok' : 'dim'),
                $this->stat('Entries', Format::count($totals['entry'] ?? 0), 'dim'),
                $this->stat('Assets', Format::count($totals['asset'] ?? 0), 'dim'),
                $this->stat('Deleted', Format::count($deleted), $deleted > 0 ? 'warn' : 'dim'),
            ],
            type: 'stacked',
            unit: '/min',
            subtitle: 'Saves, deletions and uploads per minute, by what changed.',
            empty: 'No content changes in this period.',
        );
    }
}
