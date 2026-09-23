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

    /**
     * The host's series, plus series with no host label at all: backends that
     * keep `host.name` only as a resource attribute (telemetryd) export metrics
     * unlabelled, and on such a fleet those series are this host's. An empty
     * alternative in an anchored RE2 match is exactly "label absent".
     */
    protected function scopeMatchers(): string
    {
        if ($this->host === '') {
            return '';
        }

        $escaped = strtr($this->host, ['\\' => '\\\\', '.' => '\\.', '+' => '\\+', '*' => '\\*', '?' => '\\?', '(' => '\\(', ')' => '\\)', '[' => '\\[', ']' => '\\]', '{' => '\\{', '}' => '\\}', '^' => '\\^', '$' => '\\$', '|' => '\\|']);

        return 'host_name=~"'.addcslashes($escaped, '"\\').'|"';
    }
}
