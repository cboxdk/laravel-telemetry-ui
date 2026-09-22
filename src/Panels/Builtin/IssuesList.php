<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;

/**
 * Open issues/PRs from the configured tracker (GitHub, Sentry, Linear),
 * surfaced next to the telemetry so a spike and its ticket live together.
 */
final class IssuesList extends Panel
{
    #[Param('issue_state')]
    public string $state = 'open';

    #[Param('issue_search')]
    public string $search = '';

    #[Param('issue_label')]
    public string $label = '';

    /** Filter to one tracker/repo when several are configured. */
    #[Param('issue_source')]
    public string $sourceFilter = '';

    public function data(): array
    {
        $rows = [];
        $labels = [];
        $sources = [];
        $error = null;
        $url = '';

        try {
            $configured = app(ConnectionManager::class)->issueSources();
        } catch (\Throwable $exception) {
            // A misconfigured single tracker (bad driver / missing repo) surfaces
            // as an inline error, never a 500 — a broken backend must not take
            // the page down.
            $configured = [];
            $error = $exception->getMessage();
        }

        if ($configured === []) {
            $error ??= 'No issue tracker configured. Set connections.issues (a single tracker, or a list of them for frontend/api/… repos).';
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

        $multiSource = count($sources) > 1;
        $table = [];

        foreach ($rows as $row) {
            $issue = $row['issue'];
            $labelsShown = array_slice($issue->labels, 0, 4);

            // The issue drawer resolves ids against the primary tracker only,
            // so open it in-app for a single source; otherwise go straight to
            // the tracker.
            $link = $multiSource ? Ui::url($issue->url) : Ui::issue($issue->id);

            $cells = [
                '_link' => $link,
                'id' => Ui::cell($issue->kind === 'pr' ? 'PR' : $issue->id, [
                    'badge' => $issue->kind === 'pr' ? 'PR' : $issue->id,
                    'tone' => $issue->kind === 'pr' ? 'info' : ($issue->isOpen() ? 'ok' : null),
                    'mono' => true,
                ]),
            ];

            if ($multiSource) {
                $cells['source'] = Ui::cell($row['source'], ['badge' => $row['source'], 'tone' => 'info']);
            }

            $cells['title'] = Ui::cell($issue->title, ['link' => $link]);
            // A cell carries one link: clicking the labels toggles the filter
            // on the first one (the label select control covers the rest).
            $cells['labels'] = $labelsShown === []
                ? Ui::cell('')
                : Ui::cell(implode(' · ', $labelsShown), [
                    'link' => Ui::param('issue_label', $this->label === $labelsShown[0] ? '' : $labelsShown[0]),
                ]);
            $cells['author'] = Ui::cell($issue->author ?? '—');
            $cells['comments'] = Ui::cell($issue->count !== null ? (string) $issue->count : '—', ['raw' => $issue->count, 'mono' => true]);
            $cells['updated'] = Ui::cell($issue->updatedAt?->format('d/m H:i') ?? '—', ['raw' => $issue->updatedAt?->getTimestamp(), 'mono' => true]);

            $table[] = $cells;
        }

        $columns = [Ui::col('id', '')];

        if ($multiSource) {
            $columns[] = Ui::col('source', 'Repo');
        }

        array_push(
            $columns,
            Ui::col('title', 'Title'),
            Ui::col('labels', 'Labels'),
            Ui::col('author', 'Author'),
            Ui::num('comments', 'Comments'),
            Ui::num('updated', 'Updated'),
        );

        $controls = [
            Ui::select('issue_state', 'State', in_array($this->state, ['open', 'closed', 'all'], true) ? $this->state : 'open', [
                ['value' => 'open', 'label' => 'Open'],
                ['value' => 'closed', 'label' => 'Closed'],
                ['value' => 'all', 'label' => 'All'],
            ]),
        ];

        if ($multiSource) {
            $controls[] = Ui::select('issue_source', 'Repo', $this->sourceFilter, [
                ['value' => '', 'label' => 'All repos'],
                ...array_map(static fn (string $src): array => ['value' => $src, 'label' => $src], array_values($sources)),
            ]);
        }

        if ($labels !== []) {
            $controls[] = Ui::select('issue_label', 'Label', $this->label, [
                ['value' => '', 'label' => 'All labels'],
                ...array_map(static fn (string $lbl): array => ['value' => $lbl, 'label' => $lbl], $labels),
            ]);
        }

        $controls[] = Ui::search('issue_search', 'Search', $this->search, 'Search titles…');

        return Ui::table('Issues', $columns, $table, [
            'subtitle' => $multiSource
                ? 'Open issues and pull requests across '.count($sources).' repos'
                : 'Open issues and pull requests from your tracker',
            'span' => 2,
            // A partial failure (one tracker down, others answered) is a note
            // over the rows, not an error state that hides them.
            'error' => $table === [] ? $error : null,
            'note' => $table !== [] && $error !== null ? '⚠ '.$error : null,
            'empty' => 'No matching issues. 🎉',
            'drill' => $url !== '' ? Ui::url($url) : null,
            'controls' => $controls,
        ]);
    }
}
