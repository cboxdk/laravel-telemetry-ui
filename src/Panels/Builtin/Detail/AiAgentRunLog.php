<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\AiAgentRuns;
use Cbox\TelemetryUi\Panels\ListsRows;

/**
 * The agent-runs list, scoped to one agent on its detail page: its run log, newest first.
 */
final class AiAgentRunLog extends AiAgentRuns implements ListsRows
{
    use ScopesToAiAgent;
}
