<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Contracts;

use Cbox\TelemetryUi\Queries\Results\Issue;

/**
 * An external issue tracker (GitHub, Sentry, Linear, …). The action side
 * (creating tickets) is a separate concern layered on top later; this is the
 * read side that surfaces existing issues next to your telemetry.
 */
interface IssuesSource
{
    /**
     * List issues, most recently updated first.
     *
     * @param  'open'|'closed'|'all'  $state
     * @return list<Issue>
     */
    public function issues(string $state = 'open', ?string $search = null, int $limit = 50): array;

    /**
     * Fetch a single issue by its id (with body) for the detail drawer, or
     * null if it can't be resolved.
     */
    public function issue(string $id): ?Issue;

    /**
     * A short human name for this tracker/project, for the UI header.
     */
    public function label(): string;

    /**
     * The web URL of the tracker/project itself (for a "view all" link).
     */
    public function url(): string;
}
