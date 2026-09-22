<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Analysis\ErrorGroupReport;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\CreatesIssues;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Results\Issue;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The issue page's action/context sidebar, Sentry-style: create a ticket
 * (prefilled), the tracker tickets that already mention this exception,
 * and the group's key facts — next to the trend, not below the fold.
 */
final class ErrorGroupSidebar extends Panel
{
    use ScopesToGroup;

    public function data(): array
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

        $extra = ['span' => 1];

        if ($error !== null) {
            return Ui::composite('Actions & context', [], [...$extra, 'error' => $error]);
        }

        $parts = [];

        if ($related !== []) {
            $parts[] = Ui::kv('Related tickets', array_map(static function (Issue $issue): array {
                $item = ['label' => $issue->id, 'value' => Str::limit($issue->title, 60), 'link' => Ui::issue($issue->id)];

                if ($issue->isOpen()) {
                    $item['tone'] = 'ok';
                }

                return $item;
            }, array_values($related)));
        } elseif ($canCreate) {
            $parts[] = Ui::callout('', 'No tracker tickets mention this exception yet.');
        }

        if ($report['stats'] !== null) {
            $parts[] = Ui::kv('Facts', $this->groupFacts($report, withCount: false));
        }

        $suspect = $report['suspect'];

        if ($suspect !== null) {
            $item = ['label' => $suspect['label'], 'value' => 'first seen '.$suspect['gap'].' later'];

            if ($suspect['traceId'] !== null && $suspect['traceId'] !== '') {
                $item['link'] = Ui::trace($suspect['traceId']);
            }

            $parts[] = Ui::kv('Suspect', [$item]);
        }

        return Ui::composite('Actions & context', $parts, [
            ...$extra,
            // Actions: a prefilled ticket draft for the compose form
            // (POST /api/v2/issues), and a Markdown brief to copy into an
            // LLM — the latter for anyone who can see the group, no tracker
            // write access required.
            'ticket' => $canCreate ? app(ErrorGroupReport::class)->draft($this->group, $report['stats'], $report['detail']) : null,
            'copy' => ($report['stats'] !== null || $report['detail'] !== null)
                ? ['label' => 'Copy for LLM', 'text' => app(ErrorGroupReport::class)->llmMarkdown($this->group, $report)]
                : null,
        ]);
    }
}
