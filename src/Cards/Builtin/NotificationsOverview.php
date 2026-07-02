<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Notifications sent, by channel.
 */
final class NotificationsOverview extends Card
{
    public function render(): View
    {
        [$start, $end] = $this->range();

        $metric = $this->metric('notifications_sent_total');

        try {
            $total = $this->total('sum(increase('.$metric.'['.$this->period()->promDuration().']))');

            $range = $this->metrics()->queryRange(
                'sum by (channel) (rate('.$metric.'['.$this->rateWindow().'])) * 60',
                $start,
                $end,
            );
        } catch (SourceException $exception) {
            return $this->chartCard('Notifications', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Notifications',
            series: $this->toChartSeries($range, 'channel'),
            stats: [$this->stat('Sent', Format::count($total))],
            type: 'bar',
            unit: '/min',
        );
    }
}
