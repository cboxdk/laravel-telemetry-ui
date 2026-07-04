<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Reported exceptions by class, with a link to matching error traces.
 */
final class ExceptionsTable extends Card
{
    public function render(): View
    {
        $rows = [];
        $error = null;

        try {
            $samples = $this->metrics()->query(
                'sum by (exception) (increase('.$this->metric('exceptions_reported_total').'['.$this->promDuration().']))',
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

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.exceptions-table';

        return view($view, [
            'rows' => array_slice($rows, 0, 100),
            'error' => $error,
            'errorTracesUrl' => $this->errorTracesUrl(),
            'hasIssues' => app(ConnectionManager::class)->hasIssues(),
            'canCreate' => app(ConnectionManager::class)->canCreateIssues(),
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
            ."[View error traces in the dashboard]({$this->errorTracesUrl()})\n\n"
            .'_Filed from the telemetry dashboard._';

        return [
            'title' => class_basename(Str::before($exception, ':')).' — '.(int) round($count).' in '.$this->period()->label(),
            'body' => $body,
            'labels' => ['bug'],
        ];
    }

    /**
     * The purpose-built detail page for this exception class (its occurrence
     * trend and the error traces behind it).
     */
    public function detailUrl(string $exception): string
    {
        return route('telemetry-ui.page', array_filter([
            'page' => 'exception-detail',
            'exception' => $exception,
            'period' => $this->period,
            'service' => $this->service,
            'env' => $this->environment,
        ]));
    }

    /**
     * Link to the Issues page pre-searched for this exception's short class
     * name, so a spike jumps straight to any matching ticket.
     */
    public function issuesUrl(string $exception): string
    {
        return route('telemetry-ui.page', array_filter([
            'page' => 'issues',
            'issue_state' => 'all',
            'issue_search' => class_basename(Str::before($exception, ':')),
        ]));
    }

    private function errorTracesUrl(): string
    {
        return route('telemetry-ui.page', array_filter([
            'page' => 'traces',
            'q' => '{ '.$this->traceScope('status = error').' }',
            'period' => $this->period,
            'service' => $this->service,
            'env' => $this->environment,
        ]));
    }
}
