<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\AiTools;

/**
 * The tools card, scoped to the tools one agent's runs called.
 */
final class AiAgentTools extends AiTools
{
    use ScopesToAiAgent;
}
