<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Analysis\ErrorGroupReport;
use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\CreatesIssues;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * The issue page's event deep-dive: the same panel the drawer shows —
 * message, request strip, root-cause hints, source context, stacktrace and
 * recent occurrences — rendered full-width.
 */
final class ErrorGroupDetail extends Card
{
    use ScopesToGroup;

    public function render(): View
    {
        $error = null;
        $report = ['occurrences' => [], 'stats' => null, 'detail' => null, 'request' => null, 'suspect' => null, 'releases' => []];

        try {
            $report = $this->groupReport();
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $manager = app(ConnectionManager::class);
        $canCreate = Gate::allows('manageTelemetryUi')
            && $manager->hasIssues()
            && $manager->issues() instanceof CreatesIssues;

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.error-group-detail';

        return view($view, [
            'error' => $error,
            'group' => $this->group,
            'stats' => $report['stats'],
            'occurrences' => array_slice($report['occurrences'], 0, 20),
            'detail' => $report['detail'],
            'request' => $report['request'],
            'suspect' => $report['suspect'],
            'releases' => $report['releases'],
            'canCreate' => $canCreate,
            'draft' => $canCreate ? app(ErrorGroupReport::class)->draft($this->group, $report['stats'], $report['detail']) : null,
            'lookbackDays' => ErrorGroupReport::LOOKBACK_DAYS,
        ]);
    }
}
