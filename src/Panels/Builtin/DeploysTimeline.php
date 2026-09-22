<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Annotation;
use DateTimeImmutable;

/**
 * Recent deploys within the active range — the list behind the deploy
 * annotation lines. Each links to the marker's own trace.
 */
final class DeploysTimeline extends Panel
{
    public function data(): array
    {
        $rows = array_map(static function (Annotation $deploy): array {
            $at = (new DateTimeImmutable)->setTimestamp((int) ($deploy->timestampMs / 1000));
            $row = [
                'when' => Ui::cell($at->format('d/m H:i:s'), array_filter([
                    'raw' => (int) $deploy->timestampMs,
                    'mono' => true,
                    'sub' => $deploy->endMs !== null
                        ? '→ '.(new DateTimeImmutable)->setTimestamp((int) ($deploy->endMs / 1000))->format('H:i:s')
                        : null,
                ], static fn (mixed $v): bool => $v !== null)),
                'marker' => Ui::cell($deploy->label, array_filter([
                    'badge' => $deploy->count > 1 ? '×'.$deploy->count : null,
                    'sub' => $deploy->count > 1 ? count($deploy->hosts).' hosts reported this marker' : null,
                ], static fn (?string $v): bool => $v !== null)),
                'notes' => Ui::cell($deploy->notes ?? '—'),
                'trace' => $deploy->traceId !== null && $deploy->traceId !== ''
                    ? Ui::cell(substr($deploy->traceId, 0, 8).'…', ['link' => Ui::trace($deploy->traceId), 'mono' => true])
                    : Ui::cell('—', ['tone' => 'dim']),
            ];

            // What happened after it: the errors from just before the deploy
            // to an hour after — the "did this deploy break something" view.
            $seconds = (int) ($deploy->timestampMs / 1000);
            $row['after'] = Ui::cell('errors after →', ['link' => Ui::around('errors', $seconds, 15 * 60, 60 * 60), 'tone' => 'info']);
            $row['_link'] = Ui::around('errors', $seconds, 15 * 60, 60 * 60);

            return $row;
        }, $this->annotations());

        return Ui::table('Deploys', [
            Ui::col('when', 'When'),
            Ui::col('marker', 'Marker'),
            Ui::col('notes', 'Notes'),
            Ui::num('trace', 'Trace'),
            Ui::col('after', ''),
        ], $rows, [
            'span' => 2,
            'empty' => 'No deploys in this period. Emit markers from your pipeline with `php artisan telemetry:deploy --notes="…"`.',
        ]);
    }
}
