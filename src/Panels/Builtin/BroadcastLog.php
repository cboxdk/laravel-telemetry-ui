<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;

/**
 * Each event broadcast (the `broadcast …` spans): which event, to how many
 * channels of which kind, over which driver, and what sent it.
 */
final class BroadcastLog extends SpanLog
{
    protected function prefix(): string
    {
        return 'bcast';
    }

    protected function title(): string
    {
        return 'Broadcasts';
    }

    protected function subtitle(): string
    {
        return 'Each event broadcast, newest first: the event, its channels, and what sent it';
    }

    protected function empty(): string
    {
        return 'No broadcasts traced in this period.';
    }

    protected function conditions(): array
    {
        return [TraceCondition::nil('span.broadcasting.event')];
    }

    protected function attributes(): array
    {
        return ['span.broadcasting.event', 'span.broadcasting.driver', 'span.broadcasting.channels', 'span.broadcasting.channel.count'];
    }

    protected function columns(): array
    {
        return [Ui::col('event', 'Event'), Ui::col('channels', 'Channels')];
    }

    protected function slowMs(): float
    {
        return 500.0;
    }

    protected function cells(array $attributes, MatchedSpan $span, TraceSummary $trace): array
    {
        $event = $this->str($attributes['broadcasting.event'] ?? null) ?? $span->name;

        return [
            'event' => Ui::cell(class_basename($event), array_filter([
                'mono' => true,
                'sub' => $this->str($attributes['broadcasting.driver'] ?? null),
            ])),
            'channels' => Ui::cell($this->str($attributes['broadcasting.channels'] ?? null) ?? '—', array_filter([
                'raw' => (int) $this->num($attributes['broadcasting.channel.count'] ?? null),
            ])),
        ];
    }
}
