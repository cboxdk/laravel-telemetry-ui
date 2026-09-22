<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use Cbox\TelemetryUi\Support\Format;

/**
 * The recent traces touching a single page — the browser page load and the
 * backend request behind it share one trace, so each row is the full
 * frontend → backend waterfall. The drill-down that replaces a pre-filtered
 * trace search, embedded on the page's detail page.
 */
final class PageDetailTraces extends Panel
{
    use ScopesToPage;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();

        $results = [];
        $error = null;

        if ($this->page !== '') {
            try {
                $results = $this->traces()->search(
                    $this->traceQuery(...$this->pageTraceConditions()),
                    $start,
                    $end,
                    limit: 25,
                );
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        $columns = [
            Ui::col('time', 'Time'),
            Ui::col('service', 'Service'),
            Ui::col('trace', 'Trace'),
            Ui::num('duration', 'Duration'),
            Ui::num('id', 'ID'),
        ];

        $rows = array_map(static fn (TraceSummary $summary): array => [
            'time' => Ui::cell($summary->startedAt->format('H:i:s'), ['raw' => $summary->startedAt->getTimestamp() * 1000]),
            'service' => Ui::cell($summary->rootServiceName, [
                'badge' => $summary->rootServiceName,
                'tone' => 'info',
                'dim' => ['key' => 'service.name', 'value' => $summary->rootServiceName],
            ]),
            'trace' => Ui::cell($summary->rootTraceName !== '' ? $summary->rootTraceName : '(unnamed)', ['link' => Ui::trace($summary->traceId)]),
            'duration' => Ui::cell(Format::ms($summary->durationMs), [
                'raw' => $summary->durationMs,
                'tone' => $summary->durationMs > 1000 ? 'warn' : null,
            ]),
            'id' => Ui::cell(substr($summary->traceId, 0, 8).'…', ['mono' => true, 'link' => Ui::trace($summary->traceId)]),
            '_link' => Ui::trace($summary->traceId),
        ], $results);

        return Ui::table('Recent traces', $columns, $rows, array_filter([
            'subtitle' => 'Click a row for the waterfall + host context',
            'empty' => 'No traces for this page in this period.',
            'error' => $error,
        ], static fn (?string $v): bool => $v !== null));
    }
}
