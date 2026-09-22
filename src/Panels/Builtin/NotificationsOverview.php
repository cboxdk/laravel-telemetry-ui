<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;

/**
 * Notifications sent, by channel.
 */
final class NotificationsOverview extends Panel
{
    public function data(): array
    {
        [$start, $end] = $this->range();

        $metric = $this->metric('notifications_sent_total');

        try {
            $total = $this->total($metric->increase($this->promDuration())->sumBy());

            $range = $this->metrics()->queryRange(
                $metric->rate($this->rateWindow())->sumBy('channel')->times(60),
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
