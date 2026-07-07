<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Ir;

/**
 * A LogQL line filter: keep/drop lines by substring or RE2 match against the
 * raw log body (e.g. `|= "analytics.page_view"`, `|~ "deploy|purge"`).
 */
final readonly class LineFilter implements LogStage
{
    public function __construct(
        public LineOp $op,
        public string $value,
    ) {}
}
