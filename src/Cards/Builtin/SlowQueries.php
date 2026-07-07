<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * Slowest database query spans, straight from Tempo via TraceQL — metrics
 * can't carry unbounded query text, traces can.
 */
final class SlowQueries extends Card
{
    #[Url(as: 'min_ms')]
    public int $minMs = 50;

    /** @var list<int> */
    public array $thresholds = [10, 50, 100, 250, 500, 1000];

    public function render(): View
    {
        [$start, $end] = $this->range();

        $rows = [];
        $error = null;

        try {
            $query = $this->traceQuery(
                TraceCondition::nil('span.db.query.text'),
                TraceCondition::token('duration', TraceOp::Gt, $this->minMs.'ms'),
            )->select('span.db.query.text', 'span.db.system.name');

            $results = $this->traces()->search($query, $start, $end, limit: 50);

            foreach ($results as $summary) {
                foreach ($summary->matchedSpans as $span) {
                    $query = $span->attributes['db.query.text'] ?? null;

                    if (! is_string($query) || $query === '') {
                        continue;
                    }

                    $rows[] = [
                        'query' => $query,
                        'system' => is_string($span->attributes['db.system.name'] ?? null) ? $span->attributes['db.system.name'] : '',
                        'durationMs' => $span->durationMs,
                        'traceId' => $summary->traceId,
                        'origin' => $summary->rootTraceName,
                        'startedAt' => $summary->startedAt,
                    ];
                }
            }

            usort($rows, static fn (array $a, array $b): int => $b['durationMs'] <=> $a['durationMs']);
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.slow-queries';

        return view($view, [
            'rows' => array_slice($rows, 0, 50),
            'error' => $error,
        ]);
    }

    public function traceUrl(string $traceId): string
    {
        return route('telemetry-ui.trace', ['traceId' => $traceId]);
    }
}
