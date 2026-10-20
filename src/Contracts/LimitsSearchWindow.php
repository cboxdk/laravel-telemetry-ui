<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Contracts;

/**
 * A {@see TracesSource} whose searches cover at most so much time at once.
 *
 * Tempo refuses a search (and a tag-value lookup) over more than its
 * `query_frontend.search.max_duration`, 168h by default, with a 400 and a
 * plain-text reason, so a 14- or 30-day window failed outright. A driver that
 * declares its limit searches the newest part of the window that fits, and
 * the dashboard says the window was cut, instead of failing.
 *
 * @api Implement alongside TracesSource when the store limits a search.
 */
interface LimitsSearchWindow
{
    /** The longest window one search may cover, in seconds; null for no limit. */
    public function maxSearchSeconds(): ?int;
}
