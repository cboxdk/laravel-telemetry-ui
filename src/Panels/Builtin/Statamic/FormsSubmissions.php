<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Statamic;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;

/**
 * Statamic form submissions per form.
 */
final class FormsSubmissions extends Panel
{
    public function data(): array
    {
        [$start, $end] = $this->range();

        $metric = $this->metric('statamic_forms_submissions_total');

        try {
            $total = $this->total($metric->increase($this->promDuration())->sumBy());
            $range = $this->metrics()->queryRange($metric->rate($this->rateWindow())->sumBy('form')->times(60), $start, $end);
        } catch (SourceException $exception) {
            return $this->chartCard('Forms', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Form submissions',
            series: $this->toChartSeries($range, 'form'),
            stats: [$this->stat('Submissions', Format::count($total))],
            type: 'bar',
            unit: '/min',
        );
    }
}
