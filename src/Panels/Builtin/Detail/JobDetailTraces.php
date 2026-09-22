<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * The recent runs of a single job — its traces, on the job detail page.
 */
final class JobDetailTraces extends Panel
{
    use ScopesToJob;

    public static function span(): int
    {
        return 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        [$start, $end] = $this->range();

        $results = [];
        $error = null;

        if ($this->job !== '') {
            try {
                $results = $this->traces()->search(
                    $this->traceQuery(...$this->jobTraceConditions()),
                    $start,
                    $end,
                    limit: 25,
                );
            } catch (SourceException $exception) {
                $error = $exception->getMessage();
            }
        }

        $rows = [];

        foreach ($results as $summary) {
            $rows[] = [
                '_link' => Ui::trace($summary->traceId),
                'time' => Ui::cell($summary->startedAt->format('H:i:s'), [
                    'raw' => $summary->startedAt->getTimestamp(),
                    'mono' => true,
                ]),
                'service' => Ui::cell($summary->rootServiceName, [
                    'badge' => $summary->rootServiceName,
                    'tone' => 'info',
                    'dim' => ['key' => 'service.name', 'value' => $summary->rootServiceName],
                ]),
                'trace' => Ui::cell($summary->rootTraceName !== '' ? $summary->rootTraceName : '(unnamed)', [
                    'link' => Ui::trace($summary->traceId),
                ]),
                'duration' => Ui::cell(Format::ms($summary->durationMs), [
                    'raw' => $summary->durationMs,
                    'mono' => true,
                    'tone' => $summary->durationMs > 1000 ? 'warn' : null,
                ]),
                'id' => Ui::cell(substr($summary->traceId, 0, 8).'…', [
                    'mono' => true,
                    'link' => Ui::trace($summary->traceId),
                ]),
            ];
        }

        return Ui::table('Recent runs', [
            Ui::col('time', 'Time'),
            Ui::col('service', 'Service'),
            Ui::col('trace', 'Trace'),
            Ui::num('duration', 'Duration'),
            Ui::num('id', 'ID'),
        ], $rows, [
            'subtitle' => 'Traces for this job — click a row for the waterfall + host context',
            'span' => 2,
            'error' => $error,
            'empty' => 'No traces for this job in this period.',
        ]);
    }
}
