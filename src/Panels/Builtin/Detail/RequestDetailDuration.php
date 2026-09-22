<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\RequestDuration;
use Cbox\TelemetryUi\Panels\Ui;

/**
 * The request latency card, scoped to a single route on its detail page.
 */
final class RequestDetailDuration extends RequestDuration
{
    use ScopesToRoute;

    protected function statLinks(): array
    {
        return ['AVG' => Ui::explore('requests', ['http.route='.$this->route])];
    }
}
