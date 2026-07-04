<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\RequestDuration;

/**
 * The request latency card, scoped to a single route on its detail page.
 */
final class RequestDetailDuration extends RequestDuration
{
    use ScopesToRoute;
}
