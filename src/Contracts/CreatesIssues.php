<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Contracts;

use Cbox\TelemetryUi\Queries\Results\Issue;

/**
 * An optional capability on top of {@see IssuesSource}: trackers that can
 * create issues (GitHub, Linear). The UI only offers "create ticket" when the
 * resolved source implements this — read-only sources (e.g. Sentry) don't.
 */
interface CreatesIssues
{
    /**
     * Create an issue and return the created record.
     *
     * @param  list<string>  $labels
     */
    public function createIssue(string $title, string $body, array $labels = []): Issue;
}
