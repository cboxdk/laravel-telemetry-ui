<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Contracts;

use Cbox\TelemetryUi\Queries\Results\Trace;
use DateTimeInterface;

/**
 * A traces backend that can look a trace up faster when told roughly when it
 * happened (Tempo: `/api/traces/{id}?start=&end=` searches only the blocks
 * that overlap the window, instead of every block in retention).
 *
 * @api Optional, beside {@see TracesSource}. A trace outside the window comes
 *      back with no spans; the caller then falls back to {@see TracesSource::trace()}.
 */
interface LocatesTracesInTime
{
    public function traceBetween(string $traceId, DateTimeInterface $start, DateTimeInterface $end): Trace;
}
