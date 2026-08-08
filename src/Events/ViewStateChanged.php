<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Events;

use Cbox\TelemetryUi\Support\ViewState;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The reader moved the dashboard's view state — a new time window, a new
 * auto-refresh interval, or a new service/environment scope.
 *
 * Fired once per request, only when the resolved state differs from what the
 * request arrived with, so a reader paging around inside one window is quiet.
 *
 * Listen for it when the dashboard is mounted as the whole UI and the host
 * draws its own chrome around it: a desktop shell can mirror "Last 7 days" in
 * its title bar without polling for it.
 */
final readonly class ViewStateChanged
{
    public function __construct(
        public ViewState $state,
        public ?Authenticatable $user,
    ) {}
}
