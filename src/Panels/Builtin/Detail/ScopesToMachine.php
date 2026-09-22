<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Attributes\Param;

/**
 * Scopes a card to a single reporting machine (the `?host=` on the
 * host-detail page) via the `host_name` resource label — the "this box"
 * drill-down. Distinct from {@see ScopesToHost}, which scopes to an
 * *upstream* host (`server_address`) on the outgoing page.
 */
trait ScopesToMachine
{
    #[Param('host')]
    public string $host = '';

    protected function scopeMatchers(): string
    {
        return $this->host === '' ? '' : 'host_name="'.addcslashes($this->host, '"\\').'"';
    }
}
