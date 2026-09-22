<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\OutgoingActivity;
use Cbox\TelemetryUi\Panels\Ui;

/**
 * The outgoing-requests card, scoped to one upstream host on its detail page.
 */
final class OutgoingHostActivity extends OutgoingActivity
{
    use ScopesToHost;

    public static function span(): int
    {
        return 2;
    }

    protected function statLinks(): array
    {
        return ['Conn. failures' => Ui::explore('traces', ['server.address='.$this->host, 'status=error'])];
    }
}
