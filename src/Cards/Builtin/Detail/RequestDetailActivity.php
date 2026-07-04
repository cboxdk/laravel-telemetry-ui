<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\RequestsActivity;

/**
 * The requests throughput card, scoped to a single route on its detail page.
 */
final class RequestDetailActivity extends RequestsActivity
{
    use ScopesToRoute;
}
