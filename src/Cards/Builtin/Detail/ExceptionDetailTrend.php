<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\ExceptionsOverview;

/**
 * The exceptions-over-time card, scoped to a single exception class.
 */
final class ExceptionDetailTrend extends ExceptionsOverview
{
    use ScopesToException;
}
