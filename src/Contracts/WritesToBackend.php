<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Contracts;

/**
 * Marker: this source can mutate state in the system it connects to.
 *
 * Every telemetry read driver (Tempo, Loki, Prometheus, ClickHouse, …) is
 * read-only and does NOT implement this. Only capabilities that write do — today
 * just {@see CreatesIssues}, which opens tickets in a tracker.
 *
 * @api The point of the marker is that "this app never writes to your stores"
 *      becomes a checkable property rather than a claim in a README: a host that
 *      makes that promise (e.g. a desktop client pointed at production) can
 *      assert no resolved source implements this interface, and a host can
 *      refuse the capability wholesale via `telemetry-ui.read_only`.
 */
interface WritesToBackend {}
