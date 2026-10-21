<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;

/**
 * Each message sent — its subject, how many it went to, what sent it and how
 * long the transport took (the `mail.send` spans).
 */
final class MailLog extends SpanLog
{
    protected function prefix(): string
    {
        return 'mail';
    }

    protected function title(): string
    {
        return 'Mail sent';
    }

    protected function subtitle(): string
    {
        return 'Each message, newest first: the subject, what sent it, and how long the transport took';
    }

    protected function empty(): string
    {
        return 'No mail sent in this period.';
    }

    protected function conditions(): array
    {
        return [TraceCondition::eq('name', 'mail.send')];
    }

    protected function attributes(): array
    {
        return ['span.mail.subject', 'span.mail.recipients'];
    }

    protected function columns(): array
    {
        return [Ui::col('subject', 'Subject')];
    }

    protected function cells(array $attributes, MatchedSpan $span, TraceSummary $trace): array
    {
        $to = (int) $this->num($attributes['mail.recipients'] ?? null);

        return [
            'subject' => Ui::cell($this->str($attributes['mail.subject'] ?? null) ?? '(no subject)', [
                'sub' => $to === 1 ? '1 recipient' : $to.' recipients',
            ]),
        ];
    }
}
