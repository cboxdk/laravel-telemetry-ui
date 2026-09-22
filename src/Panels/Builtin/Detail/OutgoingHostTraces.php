<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use Cbox\TelemetryUi\Support\Format;

/**
 * Recent outgoing calls to a single upstream host — the client spans, on the
 * host detail page.
 */
final class OutgoingHostTraces extends Panel
{
    use ScopesToHost;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        $results = [];
        $error = null;

        if ($this->host !== '') {
            try {
                $results = $this->traces()->search(
                    $this->traceQuery(...$this->hostTraceConditions()),
                    $start,
                    $end,
                    limit: 25,
                );
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->tracesTable('Recent calls', $results, [
            'subtitle' => 'Traces containing a call to this host — click a row for the waterfall + host context',
            'error' => $error,
            'empty' => 'No calls to this host in this period.',
        ]);
    }

    /**
     * Example traces as a table — each row opens the trace.
     *
     * @param  list<TraceSummary>  $results
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function tracesTable(string $title, array $results, array $extra): array
    {
        $rows = array_map(static fn (TraceSummary $summary): array => [
            'time' => Ui::cell($summary->startedAt->format('H:i:s'), ['raw' => $summary->startedAt->getTimestamp(), 'mono' => true]),
            'service' => Ui::cell($summary->rootServiceName, ['badge' => $summary->rootServiceName, 'tone' => 'info']),
            'trace' => Ui::cell($summary->rootTraceName !== '' ? $summary->rootTraceName : '(unnamed)', ['link' => Ui::trace($summary->traceId)]),
            'duration' => Ui::cell(Format::ms($summary->durationMs), ['raw' => $summary->durationMs, 'tone' => $summary->durationMs > 1000 ? 'warn' : null]),
            'id' => Ui::cell(substr($summary->traceId, 0, 8).'…', ['mono' => true, 'link' => Ui::trace($summary->traceId)]),
            '_link' => Ui::trace($summary->traceId),
        ], $results);

        return Ui::table($title, [
            Ui::col('time', 'Time'),
            Ui::col('service', 'Service'),
            Ui::col('trace', 'Trace'),
            Ui::num('duration', 'Duration'),
            Ui::num('id', 'ID'),
        ], $rows, array_filter($extra, static fn ($v): bool => $v !== null));
    }
}
