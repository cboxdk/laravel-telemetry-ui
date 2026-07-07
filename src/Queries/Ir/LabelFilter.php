<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Ir;

/**
 * A LogQL label filter over stream labels or structured metadata, applied as a
 * pipeline stage (e.g. `| exception_group != ""`, `| level=~"..." or
 * detected_level=~"..."`). Matchers are joined by AND, or by OR when {@see $or}.
 */
final readonly class LabelFilter implements LogStage
{
    /**
     * @param  list<LabelMatcher>  $matchers
     */
    public function __construct(
        public array $matchers,
        public bool $or = false,
    ) {}
}
