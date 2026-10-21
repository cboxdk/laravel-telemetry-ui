<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\AiCalls;
use Cbox\TelemetryUi\Panels\ListsRows;

/**
 * The model-calls list, scoped to one user, team, tenant or IP on its
 * entity page.
 */
final class AiUsageCalls extends AiCalls implements ListsRows
{
    use ScopesToAiDimension;
}
