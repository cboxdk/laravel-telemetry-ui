<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Reported exceptions by class, with a link to matching error traces.
 *
 * @phpstan-import-type Link from Ui
 */
final class ExceptionsTable extends Panel
{
    public function data(): array
    {
        $rows = [];
        $error = null;

        try {
            $samples = $this->metrics()->query(
                $this->metric('exceptions_reported_total')->increase($this->promDuration())->sumBy('exception'),
            );

            foreach ($samples as $sample) {
                if ($sample->value < 0.5) {
                    continue;
                }

                $rows[] = [
                    'exception' => $sample->labels['exception'] ?? '?',
                    'count' => $sample->value,
                ];
            }

            usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $manager = app(ConnectionManager::class);
        $hasIssues = $manager->hasIssues();
        $canCreate = $manager->canCreateIssues() && Gate::allows('manageTelemetryUi');

        $table = [];

        foreach (array_slice($rows, 0, 100) as $row) {
            $cells = [
                '_link' => Ui::page('exception-detail', ['exception' => $row['exception']]),
                'exception' => Ui::cell($row['exception'], ['mono' => true]),
                'count' => Ui::cell(Format::count($row['count']), ['raw' => $row['count'], 'mono' => true, 'tone' => 'danger']),
            ];

            if ($hasIssues) {
                $cells['tracker'] = Ui::cell('⧉ issues', ['link' => $this->issuesLink($row['exception'])]);
            }

            if ($canCreate) {
                // A prefilled draft for the SPA's compose-ticket form
                // (POST /api/v2/issues) — v1's "+ ticket" button.
                $cells['_ticket'] = $this->ticketDraft($row['exception'], $row['count']);
            }

            $table[] = $cells;
        }

        $columns = [Ui::col('exception', 'Exception'), Ui::num('count', 'Count')];

        if ($hasIssues) {
            $columns[] = Ui::num('tracker', 'Tracker');
        }

        return Ui::table('Exceptions by class', $columns, $table, [
            'span' => 2,
            'error' => $error,
            'empty' => 'No exceptions reported in this period.',
            'drill' => $this->errorTracesLink(),
        ]);
    }

    /**
     * A prefilled ticket draft for an exception spike — the "analysis" the
     * compose form opens with.
     *
     * @return array{title: string, body: string, labels: list<string>}
     */
    public function ticketDraft(string $exception, float $count): array
    {
        $scope = trim(($this->service !== '' ? $this->service : 'all services')
            .($this->environment !== '' ? ' · '.$this->environment : ''));

        $body = "**{$exception}**\n\n"
            .'`'.(int) round($count)."` occurrences in the last {$this->period()->label()} on {$scope}.\n\n"
            .''
            .'_Filed from the telemetry dashboard._';

        return [
            'title' => class_basename(Str::before($exception, ':')).' — '.(int) round($count).' in '.$this->period()->label(),
            'body' => $body,
            'labels' => ['bug'],
        ];
    }

    /**
     * The Issues page pre-searched for this exception's short class name, so
     * a spike jumps straight to any matching ticket.
     *
     * @return Link
     */
    private function issuesLink(string $exception): array
    {
        return Ui::page('issues', [
            'issue_state' => 'all',
            'issue_search' => class_basename(Str::before($exception, ':')),
        ]);
    }

    /**
     * @return Link
     */
    private function errorTracesLink(): array
    {
        return Ui::page('traces', ['q' => '{ '.$this->traceScope('status = error').' }']);
    }
}
