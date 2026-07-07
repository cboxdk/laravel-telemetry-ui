<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Queries\Ir\TraceCondition;

/**
 * The live request log narrowed to Livewire update requests — rows show the
 * component(s) behind each update, and the Components card is the grouped
 * sibling via the shared req_view toggle.
 */
final class LivewireRequestLog extends RequestLog
{
    protected function extraTraceConditions(): array
    {
        return [TraceCondition::re('span.http.route', 'livewire:.*')];
    }
}
