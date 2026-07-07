<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Illuminate\Contracts\View\View;

/**
 * Reverb WebSocket message throughput by direction.
 */
final class ReverbMessages extends Card
{
    public function render(): View
    {
        $metric = $this->metric('reverb_messages_total');

        return $this->promChart(
            title: 'Reverb messages',
            promql: $metric->rate($this->rateWindow())->sumBy('direction')->times(60),
            subtitle: 'WebSocket messages per minute, sent vs received',
            seriesLabel: 'direction',
            type: 'area',
            unit: 'msg/min',
            span: 2,
            stat: 'Messages',
            statQuery: $metric->increase($this->promDuration())->sumBy(),
        );
    }
}
