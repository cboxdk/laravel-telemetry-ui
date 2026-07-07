<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Livewire\Attributes\Url;

/**
 * Scopes a card to a single upstream host (the `?host=` on the outgoing-detail
 * page) — the external dependency drill-down.
 */
trait ScopesToHost
{
    #[Url(as: 'host')]
    public string $host = '';

    protected function scopeMatchers(): string
    {
        return $this->host === '' ? '' : 'server_address="'.addcslashes($this->host, '"\\').'"';
    }

    /**
     * @return list<TraceCondition>
     */
    protected function hostTraceConditions(): array
    {
        return [
            TraceCondition::eq('span.server.address', $this->host),
            TraceCondition::token('kind', TraceOp::Eq, 'client'),
        ];
    }
}
