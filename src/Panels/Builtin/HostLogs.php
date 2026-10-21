<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Builtin\Detail\ScopesToMachine;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;

/**
 * The log lines one machine wrote (the `host_name` every record carries),
 * on the host page; every machine's on the System page.
 */
final class HostLogs extends LogViewer
{
    use ScopesToMachine;

    protected function narrow(LogQuery $query): LogQuery
    {
        return $this->host === '' ? $query : $query->whereLabel('host_name', MatchOp::Eq, $this->host);
    }

    protected function title(): string
    {
        return $this->host === '' ? 'Machine logs' : 'Logs from this host';
    }

    protected function tails(): bool
    {
        return $this->host === '';
    }
}
