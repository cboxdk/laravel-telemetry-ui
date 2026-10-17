<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiUsage;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Support\Format;

/**
 * Calls to one model per minute, with the failed ones beside them.
 */
final class AiModelActivity extends Panel
{
    use ReadsAiUsage;
    use ScopesToAiModel {
        ScopesToAiModel::scopedModel insteadof ReadsAiUsage;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();
        $p = $this->promDuration();

        $calls = $this->metric(self::AI_DURATION.'_count');
        $failed = $this->metric(self::AI_DURATION.'_count', 'error_type!=""');

        try {
            $series = [];

            foreach (['Calls' => [$calls, '#60a5fa'], 'Failed' => [$failed, '#f87171']] as $label => [$metric, $color]) {
                foreach ($this->metrics()->queryRange($metric->rate($this->rateWindow())->sumBy()->times(60), $start, $end) as $timeSeries) {
                    $series[] = ['name' => $label, 'data' => $timeSeries->toChartData(), 'color' => $color];
                }
            }

            $total = $this->total($calls->increase($p)->sumBy());
            $errors = $this->total($failed->increase($p)->sumBy());
        } catch (SourceException $exception) {
            return $this->chartCard('Calls', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Calls',
            subtitle: 'Calls to this model per minute',
            series: $series,
            stats: [
                $this->stat('Calls', Format::count($total)),
                $this->stat('Failed', Format::count($errors), $errors > 0 ? 'danger' : 'dim'),
            ],
            type: 'area',
            unit: '/min',
            empty: 'No calls to this model recorded in this period.',
        );
    }
}
