<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Illuminate\Contracts\View\View;

/**
 * Requests rejected by a rate limiter (429s), by limiter name — a traffic
 * spike, a misbehaving client or a limit set too tight all show up here.
 */
final class RateLimits extends Card
{
    public function render(): View
    {
        $metric = $this->metric('rate_limit_exceeded_total');

        return $this->promChart(
            title: 'Rate limiting',
            promql: 'sum by (limiter) (rate('.$metric.'['.$this->rateWindow().'])) * 60',
            subtitle: 'Requests rejected with 429 per minute, by throttle limiter',
            seriesLabel: 'limiter',
            type: 'area',
            unit: 'req/min',
            stat: 'Rejected',
            statQuery: 'sum(increase('.$metric.'['.$this->promDuration().']))',
        );
    }
}
