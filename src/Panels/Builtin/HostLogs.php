<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Builtin\Detail\ScopesToMachine;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Support\ScopeLabels;

/**
 * The log lines one machine wrote (the host label every record carries,
 * `telemetry-ui.scope.labels.logs.host`),
 * on the host page; every machine's on the System page.
 */
final class HostLogs extends LogViewer
{
    use ScopesToMachine;

    /** A machine's logs are mostly not the apps': the journal, the proxy, the containers. */
    protected function logsFollowFleet(): bool
    {
        return false;
    }

    protected function narrow(LogQuery $query): LogQuery
    {
        return $this->host === '' ? $query : $query->whereLabel(ScopeLabels::logs('host'), MatchOp::Eq, $this->host);
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
