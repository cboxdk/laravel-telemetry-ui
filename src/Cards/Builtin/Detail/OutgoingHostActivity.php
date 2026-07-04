<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Cards\Builtin\OutgoingActivity;

/**
 * The outgoing-requests card, scoped to one upstream host on its detail page.
 */
final class OutgoingHostActivity extends OutgoingActivity
{
    use ScopesToHost;
}
