<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\AiLatency;

/**
 * The chat-latency card, scoped to one model on its detail page.
 */
final class AiModelLatency extends AiLatency
{
    use ScopesToAiModel;
}
