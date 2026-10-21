<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Explore\ErrorExplorer;
use Cbox\TelemetryUi\Panels\ListsRows;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\DisplayTime;
use Illuminate\Support\Str;

/**
 * Each exception as it happened — not grouped: when, which, what it said,
 * where (backend or browser), for whom, and its trace. The groups above say
 * what keeps failing; these rows say what failed just now.
 */
final class ExceptionLog extends Panel implements ListsRows
{
    private const LIMIT = 100;

    public static function span(): int
    {
        return 3;
    }

    public function data(): array
    {
        $rows = [];
        $error = null;

        try {
            $occurrences = app(ErrorExplorer::class)->occurrences($this->scope, self::LIMIT);
            usort($occurrences, static fn (array $a, array $b): int => $b['nano'] <=> $a['nano']);

            foreach (array_slice($occurrences, 0, self::LIMIT) as $o) {
                $at = intdiv($o['nano'], 1_000_000_000);
                $link = $o['traceId'] !== null ? Ui::trace($o['traceId']) : Ui::error($o['group']);

                $rows[] = [
                    'when' => Ui::cell(DisplayTime::at($at, 'd/m H:i:s'), ['raw' => $at, 'mono' => true]),
                    'exception' => Ui::cell(class_basename($o['type'] !== '' ? $o['type'] : 'Exception'), [
                        'mono' => true,
                        'tone' => 'danger',
                        'link' => Ui::error($o['group']),
                        'sub' => Str::limit($o['message'], 160),
                    ]),
                    'source' => Ui::cell($o['frontend'] ? 'browser' : 'server', ['badge' => $o['frontend'] ? 'browser' : 'server']),
                    'service' => Ui::cell($o['service'] !== '' ? $o['service'] : '—', ['mono' => true, 'tone' => $o['service'] === '' ? 'dim' : null]),
                    'user' => Ui::cell($o['user'] !== '' ? '#'.$o['user'] : '—', ['mono' => true, 'tone' => $o['user'] === '' ? 'dim' : null]),
                    '_link' => $link,
                ];
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        return Ui::table('Recent exceptions', [
            Ui::col('when', 'When'),
            Ui::col('exception', 'Exception'),
            Ui::col('source', 'Where'),
            Ui::col('service', 'Service'),
            Ui::col('user', 'User'),
        ], $rows, [
            'subtitle' => 'Each exception as it happened, newest first. Click a row for its trace, the name for its group',
            'span' => 3,
            'error' => $error,
            'empty' => 'No exceptions in this period.',
        ]);
    }
}
