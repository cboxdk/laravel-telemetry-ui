<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Statamic;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Statamic form submissions per form.
 */
final class FormsSubmissions extends Card
{
    public function render(): View
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
