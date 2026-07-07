<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Analysis\ErrorGroupReport;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Livewire\Attributes\Url;

/**
 * Scopes a card to one error group (the `?group=` on the error-detail page)
 * and fetches its shared, per-request-memoized report.
 *
 * @phpstan-import-type Report from ErrorGroupReport
 */
trait ScopesToGroup
{
    #[Url(as: 'group')]
    public string $group = '';

    /**
     * @return Report
     *
     * @throws SourceException
     */
    protected function groupReport(): array
    {
        if (! ErrorGroupReport::validId($this->group)) {
            return ['occurrences' => [], 'stats' => null, 'detail' => null, 'request' => null, 'suspect' => null, 'releases' => []];
        }

        return app(ErrorGroupReport::class)->for(
            $this->group,
            $this->logSelector(),
            $this->traceQuery(
                TraceCondition::token('span.browser', TraceOp::Eq, 'true'),
                TraceCondition::nil('span.exception.type'),
            ),
        );
    }
}
