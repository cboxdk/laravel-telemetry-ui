<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A dashboard page was viewed. Listen for an audit trail ("who looked at what")
 * or usage metering (page views per tenant) when embedding in a hosted app.
 */
final readonly class DashboardViewed
{
    public function __construct(
        public ?Authenticatable $user,
        public string $page,
        public string $service,
        public string $environment,
    ) {}
}
