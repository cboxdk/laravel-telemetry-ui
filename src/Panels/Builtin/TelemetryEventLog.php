<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\ListsRows;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\DisplayTime;

/**
 * A row per telemetry event of a family — an autoscaler decision, a Horizon
 * incident, a cache purge — newest first, with what it said. Events land in
 * the logs store as their name, carrying their attributes as labels; this
 * reads them back one by one, where the charts only count them.
 *
 * A subclass names the family ({@see pattern()}) and its words; the base
 * drops the resource labels every record carries (service, host, SDK) so
 * what is left is what the event itself said.
 */
abstract class TelemetryEventLog extends Panel implements ListsRows
{
    protected const LIMIT = 100;

    /** Labels every record carries, by prefix: not what the event said. */
    private const RESOURCE = [
        'service_', 'deployment_', 'host_', 'process_', 'telemetry_', 'severity_', 'scope_', 'os_',
        'laravel_version', 'detected_level', 'trace_id', 'span_id', 'trace_flags', 'k8s_', 'container_', 'cloud_',
    ];

    public static function span(): int
    {
        return 2;
    }

    /** Prefix for this log's own query parameters, unique on its page. */
    abstract protected function prefix(): string;

    /** A regex the event names match (`queue_autoscale\.[a-z_.]+`). */
    abstract protected function pattern(): string;

    abstract protected function title(): string;

    abstract protected function subtitle(): string;

    abstract protected function empty(): string;

    /** How an event of this name reads at a glance: null, 'warn' or 'danger'. */
    protected function tone(string $event): ?string
    {
        return preg_match('/(fail|breach|trip|out_of_memory|error)/', $event) === 1
            ? 'danger'
            : (preg_match('/(long_wait|probing|skipped)/', $event) === 1 ? 'warn' : null);
    }

    public function data(): array
    {
        [$start, $end] = $this->range();
        $kind = (string) ($this->scope->params[$this->prefix().'_event'] ?? '');

        $events = [];
        $error = null;

        try {
            foreach ($this->logs()->query($this->logSelector()->lineMatches('^('.$this->pattern().')$'), $start, $end, limit: static::LIMIT) as $entry) {
                $events[] = [trim($entry->line), $entry->timestampNano, $entry->labels];
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $counts = array_count_values(array_column($events, 0));
        ksort($counts);
        $shown = $kind === '' ? $events : array_values(array_filter($events, static fn (array $e): bool => $e[0] === $kind));
        usort($shown, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        $rows = array_map(function (array $event): array {
            [$name, $nano, $labels] = $event;
            $at = intdiv($nano, 1_000_000_000);
            $traceId = $labels['trace_id'] ?? '';
            $link = $traceId !== '' ? Ui::trace($traceId) : Ui::around('errors', $at, 15 * 60, 60 * 60);
            $said = $this->said($labels);

            return [
                'when' => Ui::cell(DisplayTime::at($at, 'd/m H:i:s'), ['raw' => $at, 'mono' => true]),
                'event' => Ui::cell($name, array_filter(['mono' => true, 'tone' => $this->tone($name), 'sub' => $labels['host_name'] ?? null])),
                'details' => Ui::cell($said !== '' ? $said : '—', $said === '' ? ['tone' => 'dim'] : []),
                '_link' => $link,
            ];
        }, $shown);

        $options = [['value' => '', 'label' => 'All events']];
        foreach ($counts as $name => $count) {
            $options[] = ['value' => (string) $name, 'label' => $name.' ('.$count.')'];
        }

        return Ui::table($this->title(), [
            Ui::col('when', 'When'),
            Ui::col('event', 'Event'),
            Ui::col('details', 'Details'),
        ], $rows, [
            'subtitle' => $this->subtitle(),
            'span' => static::span(),
            'error' => $error,
            'empty' => $kind !== '' ? 'No events of this kind in this period.' : $this->empty(),
            'controls' => [Ui::select($this->prefix().'_event', 'Event', $kind, $options)],
        ]);
    }

    /**
     * What the event itself carried, as `key value` pairs.
     *
     * @param  array<string, string>  $labels
     */
    private function said(array $labels): string
    {
        $pairs = [];

        foreach ($labels as $key => $value) {
            if ($value === '') {
                continue;
            }

            foreach (self::RESOURCE as $resource) {
                if (str_starts_with($key, $resource)) {
                    continue 2;
                }
            }

            $pairs[] = str_replace('_', '.', $key).' '.$value;
        }

        return implode(' · ', $pairs);
    }
}
