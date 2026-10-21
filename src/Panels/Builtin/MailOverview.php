<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Support\Format;

/**
 * Mail messages sent.
 */
final class MailOverview extends Panel
{
    public function data(): array
    {
        [$start, $end] = $this->range();

        $metric = $this->metric('mail_sent_total');

        try {
            $total = $this->total($metric->increase($this->promDuration())->sumBy());
            // Sends the transport refused (laravel-telemetry 3.8+; none before).
            $failed = $this->total($this->metric('mail_failed_total')->increase($this->promDuration())->sumBy());

            $range = $this->metrics()->queryRange(
                $metric->rate($this->rateWindow())->sumBy()->times(60),
                $start,
                $end,
            );
        } catch (SourceException $exception) {
            return $this->chartCard('Mail', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Mail',
            series: isset($range[0]) ? [['name' => 'Sent', 'data' => $range[0]->toChartData(), 'color' => '#34d399']] : [],
            stats: [$this->stat('Sent', Format::count($total)), $this->stat('Failed', Format::count($failed), $failed > 0 ? 'danger' : null)],
            type: 'bar',
            unit: '/min',
        );
    }
}
