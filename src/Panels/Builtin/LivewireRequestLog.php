<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Queries\Ir\TraceCondition;

/**
 * The live request log narrowed to Livewire update requests — rows show the
 * component(s) behind each update; the Components panel is the grouped
 * sibling, shown alongside.
 */
final class LivewireRequestLog extends RequestLog
{
    protected function extraTraceConditions(): array
    {
        return [TraceCondition::re('span.http.route', 'livewire:.*')];
    }
}
