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
 * The issue page's action/context sidebar, Sentry-style: create a ticket
 * (prefilled), the tracker tickets that already mention this exception,
 * and the group's key facts — next to the trend, not below the fold.
 */
final class ErrorGroupSidebar extends Card
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
        $hasIssues = $manager->hasIssues();
        $canCreate = $hasIssues && Gate::allows('manageTelemetryUi') && $manager->issues() instanceof CreatesIssues;

        // Tickets already filed for this exception: search the tracker by
        // the short class name. Fail-open — a tracker hiccup costs the list.
        $related = [];
        $type = is_string($report['detail']['type'] ?? null) ? $report['detail']['type'] : '';

        if ($hasIssues && $type !== '') {
            try {
                $related = array_slice($manager->issues()->issues('all', class_basename($type)), 0, 5);
            } catch (SourceException) {
                $related = [];
            }
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.error-group-sidebar';

        return view($view, [
            'error' => $error,
            'group' => $this->group,
            'stats' => $report['stats'],
            'detail' => $report['detail'],
            'suspect' => $report['suspect'],
            'related' => $related,
            'canCreate' => $canCreate,
            'draft' => $canCreate ? app(ErrorGroupReport::class)->draft($this->group, $report['stats'], $report['detail']) : null,
            // Markdown brief for pasting into an LLM — available to anyone who
            // can see the group, no issue-tracker write access required.
            'llm' => ($report['stats'] !== null || $report['detail'] !== null)
                ? app(ErrorGroupReport::class)->llmMarkdown($this->group, $report)
                : null,
            'tracesUrl' => $this->pageUrl('traces'),
        ]);
    }
}
