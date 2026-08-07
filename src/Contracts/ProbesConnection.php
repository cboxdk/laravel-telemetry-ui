<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Contracts;

use Cbox\TelemetryUi\Connectors\ProbeResult;

/**
 * A driver that can answer "is this connection actually usable?" without
 * running a real dashboard query.
 *
 * @api Implement alongside a signal source to give hosts a precise connection
 *      test. Which path proves a backend is that backend (`/api/echo` for
 *      Tempo, `/api/v1/status/buildinfo` for Prometheus, `SELECT 1` for
 *      ClickHouse) is driver knowledge and belongs here, not in the host.
 */
interface ProbesConnection
{
    /**
     * Probe the connection.
     *
     * MUST NOT throw: a probe's whole job is to turn a failure into a
     * classified answer, so every failure mode — including a malformed config —
     * comes back as a {@see ProbeResult} with a non-Ok status.
     */
    public function probe(): ProbeResult;
}
