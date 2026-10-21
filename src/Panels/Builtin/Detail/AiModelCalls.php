<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Panels\Builtin\AiCalls;
use Cbox\TelemetryUi\Panels\ListsRows;

/**
 * The model-calls list, scoped to one model on its detail page.
 */
final class AiModelCalls extends AiCalls implements ListsRows
{
    use ScopesToAiModel;
}
