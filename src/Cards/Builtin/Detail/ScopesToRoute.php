<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Livewire\Attributes\Url;

/**
 * Scopes a card to a single HTTP route (the `?route=` from the request-detail
 * page), so a reused overview/chart card renders that one route's numbers.
 */
trait ScopesToRoute
{
    #[Url(as: 'route')]
    public string $route = '';

    protected function scopeMatchers(): string
    {
        return $this->route === '' ? '' : 'http_route="'.addcslashes($this->route, '"\\').'"';
    }

    /**
     * The trace-scope conditions for this route (TraceQL, server spans).
     *
     * @return list<TraceCondition>
     */
    protected function routeTraceConditions(): array
    {
        return [
            TraceCondition::eq('span.http.route', $this->route),
            TraceCondition::token('kind', TraceOp::Eq, 'server'),
        ];
    }
}
