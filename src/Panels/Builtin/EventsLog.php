<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\ListsRows;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Annotation;
use Cbox\TelemetryUi\Support\DisplayTime;
use DateTimeImmutable;

/**
 * Every marker in the window — deploys, versions, migrations, incidents,
 * scaling, cache purges (Statamic's included), feature flags — the list
 * behind the dashed lines on every chart. Filterable by kind; each row
 * opens the errors around it, and its own trace when it has one.
 */
final class EventsLog extends Panel implements ListsRows
{
    #[Param('kind')]
    public string $kind = '';

    public static function span(): int
    {
        return 3;
    }

    public function data(): array
    {
        $all = $this->annotations();

        /** @var array<string, array{event?: string, label?: string}> $markers */
        $markers = (array) config('telemetry-ui.annotations.markers', []);
        $labels = [];
        foreach ($markers as $marker) {
            if (isset($marker['event'], $marker['label'])) {
                $labels[$marker['event']] = $marker['label'];
            }
        }

        $counts = [];
        foreach ($all as $annotation) {
            $counts[$annotation->kind] = ($counts[$annotation->kind] ?? 0) + 1;
        }
        arsort($counts);

        $shown = $this->kind === '' ? $all : array_values(array_filter($all, fn (Annotation $a): bool => $a->kind === $this->kind));

        $rows = array_map(static function (Annotation $event) use ($labels): array {
            $at = (new DateTimeImmutable)->setTimestamp((int) ($event->timestampMs / 1000));
            $seconds = (int) ($event->timestampMs / 1000);

            $row = [
                'when' => Ui::cell(DisplayTime::at($at, 'd/m H:i:s'), array_filter([
                    'raw' => (int) $event->timestampMs,
                    'mono' => true,
                    'sub' => $event->endMs !== null
                        ? '→ '.DisplayTime::at((int) ($event->endMs / 1000), 'H:i:s')
                        : null,
                ], static fn (mixed $v): bool => $v !== null)),
                'kind' => Ui::cell($labels[$event->kind] ?? $event->kind, ['badge' => 'info']),
                'event' => Ui::cell($event->label, array_filter([
                    'badge' => $event->count > 1 ? '×'.$event->count : null,
                    'sub' => $event->hosts !== [] ? implode(', ', array_slice($event->hosts, 0, 3)).(count($event->hosts) > 3 ? ' +'.(count($event->hosts) - 3) : '') : null,
                ], static fn (?string $v): bool => $v !== null)),
                'notes' => Ui::cell($event->notes ?? '—', $event->notes === null ? ['tone' => 'dim'] : []),
                'trace' => $event->traceId !== null && $event->traceId !== ''
                    ? Ui::cell(substr($event->traceId, 0, 8).'…', ['link' => Ui::trace($event->traceId), 'mono' => true])
                    : Ui::cell('—', ['tone' => 'dim']),
                'after' => Ui::cell('errors after →', ['link' => Ui::around('errors', $seconds, 15 * 60, 60 * 60), 'tone' => 'info']),
                '_link' => Ui::around('errors', $seconds, 15 * 60, 60 * 60),
            ];

            return $row;
        }, $shown);

        $options = [['value' => '', 'label' => 'All kinds']];
        foreach (array_unique([...array_keys($counts), ...($this->kind !== '' ? [$this->kind] : [])]) as $kind) {
            $options[] = ['value' => $kind, 'label' => ($labels[$kind] ?? $kind).(isset($counts[$kind]) ? ' ('.$counts[$kind].')' : '')];
        }

        return Ui::table('Events', [
            Ui::col('when', 'When'),
            Ui::col('kind', 'Kind'),
            Ui::col('event', 'Event'),
            Ui::col('notes', 'Notes'),
            Ui::num('trace', 'Trace'),
            Ui::col('after', ''),
        ], $rows, [
            'subtitle' => 'Deploys, versions, migrations, incidents, cache purges — every marker drawn on the charts.',
            'span' => 3,
            'controls' => [Ui::select('kind', 'Kind', $this->kind, $options)],
            'empty' => $this->kind !== ''
                ? 'No events of this kind in this period.'
                : 'No events in this period. Emit deploy markers from your pipeline with `php artisan telemetry:deploy --notes="…"`; other markers are configured under `telemetry-ui.annotations.markers`.',
        ]);
    }
}
