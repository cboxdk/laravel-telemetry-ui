<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;

/**
 * Each notification delivery, one row per channel — which notification, over
 * which channel, whether it got there (the `notification.send` spans). A
 * delivery the notification called off itself (`shouldSend`) is not a span,
 * so it is not here; the Notifications chart counts those.
 */
final class NotificationLog extends SpanLog
{
    protected function prefix(): string
    {
        return 'notif';
    }

    protected function title(): string
    {
        return 'Notifications sent';
    }

    protected function subtitle(): string
    {
        return 'Each delivery, one row per channel: which notification, where to, and whether it got there';
    }

    protected function empty(): string
    {
        return 'No notifications sent in this period.';
    }

    protected function conditions(): array
    {
        return [TraceCondition::eq('name', 'notification.send')];
    }

    protected function attributes(): array
    {
        return ['span.notification.class', 'span.notification.channel'];
    }

    protected function columns(): array
    {
        return [Ui::col('notification', 'Notification'), Ui::col('channel', 'Channel')];
    }

    protected function cells(array $attributes, MatchedSpan $span, TraceSummary $trace): array
    {
        $class = $this->str($attributes['notification.class'] ?? null) ?? '(unknown)';
        $channel = $this->str($attributes['notification.channel'] ?? null) ?? '—';
        $short = class_basename($class);

        return [
            'notification' => Ui::cell($short, ['mono' => true, ...($short !== $class ? ['sub' => $class] : [])]),
            'channel' => Ui::cell($channel, ['badge' => $channel]),
        ];
    }
}
