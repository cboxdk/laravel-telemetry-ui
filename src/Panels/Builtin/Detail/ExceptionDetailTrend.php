<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\ExceptionsOverview;

/**
 * The exceptions-over-time card, scoped to a single exception class.
 */
final class ExceptionDetailTrend extends ExceptionsOverview
{
    use ScopesToException;
}
