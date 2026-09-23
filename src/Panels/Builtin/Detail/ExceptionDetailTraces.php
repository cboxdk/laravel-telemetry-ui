<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use Cbox\TelemetryUi\Support\Format;

/**
 * Error traces in scope for an exception detail page — the requests that blew
 * up, drilling from the class down to individual failures.
 */
final class ExceptionDetailTraces extends Panel
{
    use ScopesToException;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        $results = [];
        $error = null;

        try {
            $results = $this->traces()->search(
                $this->traceQuery(TraceCondition::token('status', TraceOp::Eq, 'error')),
                $start,
                $end,
                limit: 25,
            );
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $rows = array_map(static fn (TraceSummary $summary): array => [
            '_link' => Ui::trace($summary->traceId, $summary->startedAt),
            'time' => Ui::cell($summary->startedAt->format('H:i:s'), ['raw' => $summary->startedAt->getTimestamp(), 'mono' => true]),
            'service' => Ui::cell($summary->rootServiceName, ['badge' => $summary->rootServiceName, 'tone' => 'info']),
            'trace' => Ui::cell($summary->rootTraceName !== '' ? $summary->rootTraceName : '(unnamed)', ['link' => Ui::trace($summary->traceId, $summary->startedAt)]),
            'duration' => Ui::cell(Format::ms($summary->durationMs), ['raw' => $summary->durationMs, 'mono' => true, 'tone' => $summary->durationMs > 1000 ? 'warn' : null]),
            'id' => Ui::cell(substr($summary->traceId, 0, 8).'…', ['mono' => true, 'link' => Ui::trace($summary->traceId, $summary->startedAt)]),
        ], $results);

        return Ui::table('Recent error traces', [
            Ui::col('time', 'Time'),
            Ui::col('service', 'Service'),
            Ui::col('trace', 'Trace'),
            Ui::num('duration', 'Duration'),
            Ui::num('id', 'ID'),
        ], $rows, [
            'subtitle' => 'Failed requests in this scope — click a row for the waterfall + host context',
            'span' => 2,
            'error' => $error,
            'empty' => 'No error traces in this period.',
        ]);
    }
}
