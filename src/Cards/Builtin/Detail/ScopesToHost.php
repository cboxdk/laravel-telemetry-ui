<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

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

    protected function hostTraceScope(): string
    {
        return 'span.server.address = "'.addcslashes($this->host, '"\\').'" && kind = client';
    }
}
