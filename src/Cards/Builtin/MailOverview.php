<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Mail messages sent.
 */
final class MailOverview extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $metric = $this->metric('mail_sent_total');

        try {
            $total = $this->total('sum(increase('.$metric.'['.$this->period()->promDuration().']))');

            $range = $this->metrics()->queryRange(
                'sum(rate('.$metric.'['.$this->rateWindow().'])) * 60',
                $start,
                $end,
            );
        } catch (SourceException $exception) {
            return $this->chartCard('Mail', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Mail',
            series: isset($range[0]) ? [['name' => 'Sent', 'data' => $range[0]->toChartData(), 'color' => '#34d399']] : [],
            stats: [$this->stat('Sent', Format::count($total))],
            type: 'bar',
            unit: '/min',
        );
    }
}
