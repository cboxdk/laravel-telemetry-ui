<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

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
     * The trace-scope condition for this route (TraceQL, server spans).
     */
    protected function routeTraceScope(): string
    {
        return 'span.http.route = "'.addcslashes($this->route, '"\\').'" && kind = server';
    }
}
