<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * Open issues/PRs from the configured tracker (GitHub, Sentry, Linear),
 * surfaced next to the telemetry so a spike and its ticket live together.
 */
final class IssuesList extends Card
{
    #[Url(as: 'issue_state')]
    public string $state = 'open';

    #[Url(as: 'issue_search')]
    public string $search = '';

    #[Url(as: 'issue_label')]
    public string $label = '';

    /** Filter to one tracker/repo when several are configured. */
    #[Url(as: 'issue_source')]
    public string $sourceFilter = '';

    public function render(): View
    {
        $rows = [];
        $labels = [];
        $sources = [];
        $error = null;
        $url = '';

        $configured = app(ConnectionManager::class)->issueSources();

        if ($configured === []) {
            $error = 'No issue tracker configured. Set connections.issues (a single tracker, or a list of them for frontend/api/… repos).';
        } else {
            $state = in_array($this->state, ['open', 'closed', 'all'], true) ? $this->state : 'open';
            $sources = array_map(static fn (array $s): string => $s['label'], $configured);
            $url = $configured[0]['source']->url();

            foreach ($configured as $s) {
                if ($this->sourceFilter !== '' && $s['label'] !== $this->sourceFilter) {
                    continue;
                }

                try {
                    foreach ($s['source']->issues($state, $this->search !== '' ? $this->search : null, limit: 50) as $issue) {
                        $rows[] = ['issue' => $issue, 'source' => $s['label']];
                    }
                } catch (SourceException $exception) {
                    // One tracker being down shouldn't hide the others.
                    $error ??= $s['label'].': '.$exception->getMessage();
                }
            }

            // Newest first across all trackers.
            usort($rows, static fn (array $a, array $b): int => ($b['issue']->updatedAt?->getTimestamp() ?? 0) <=> ($a['issue']->updatedAt?->getTimestamp() ?? 0));

            $labels = collect($rows)->flatMap(fn (array $r): array => $r['issue']->labels)->unique()->sort()->values()->all();

            if ($this->label !== '') {
                $rows = array_values(array_filter($rows, fn (array $r): bool => in_array($this->label, $r['issue']->labels, true)));
            }
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.issues-list';

        return view($view, [
            'rows' => $rows,
            'labels' => $labels,
            'sources' => $sources,
            'multiSource' => count($sources) > 1,
            'error' => $error,
            'url' => $url,
        ]);
    }

    public function filterLabel(string $label): void
    {
        $this->label = $this->label === $label ? '' : $label;
    }
}
