<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Support\Format;

/**
 * Slowest database query spans, straight from Tempo via TraceQL — metrics
 * can't carry unbounded query text, traces can.
 */
final class SlowQueries extends Panel
{
    #[Param('min_ms')]
    public int $minMs = 50;

    public static function span(): int
    {
        return 2;
    }

    /** @var list<int> */
    public array $thresholds = [10, 50, 100, 250, 500, 1000];

    public function data(): array
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

        $table = array_map(static fn (array $row): array => [
            'query' => Ui::cell($row['query'], ['mono' => true, 'link' => Ui::trace($row['traceId'])]),
            'origin' => Ui::cell($row['origin']),
            'duration' => Ui::cell(Format::ms($row['durationMs']), ['raw' => $row['durationMs'], 'tone' => 'warn']),
            'when' => Ui::cell($row['startedAt']->format('H:i:s'), ['raw' => $row['startedAt']->getTimestamp(), 'mono' => true]),
            '_link' => Ui::trace($row['traceId']),
        ], array_slice($rows, 0, 50));

        return Ui::table('Slowest queries', [
            Ui::col('query', 'Query'),
            Ui::col('origin', 'Origin'),
            Ui::num('duration', 'Duration'),
            Ui::num('when', 'When'),
        ], $table, array_filter([
            'subtitle' => 'Individual DB query spans sampled from traces — click to open the full trace',
            'error' => $error,
            'empty' => 'No query spans above '.$this->minMs.'ms in this period.',
            'note' => 'Sampled from the most recent matching traces (Tempo search). Query text is truncated and redacted at emit time.',
            'controls' => [Ui::select('min_ms', 'Slower than', (string) $this->minMs, array_map(
                static fn (int $t): array => ['value' => (string) $t, 'label' => $t.'ms'],
                $this->thresholds,
            ))],
        ], static fn ($v): bool => $v !== null));
    }
}
