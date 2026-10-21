<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;

/**
 * Each message sent — its subject, who it went to, what sent it and how long
 * the transport took (the `mail.send` spans). The addresses are there when
 * laravel-telemetry records them (`instrument.mail_recipients`), and then a
 * search finds every send to one of them, in To, Cc or Bcc.
 */
final class MailLog extends SpanLog
{
    private const FIELDS = ['mail.to' => 'To', 'mail.cc' => 'Cc', 'mail.bcc' => 'Bcc'];

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
        return 'Each message, newest first: the subject, who it went to, and what sent it';
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
        return ['span.mail.subject', 'span.mail.recipients', 'span.mail.to', 'span.mail.cc', 'span.mail.bcc'];
    }

    protected function searchFields(): array
    {
        return ['span.mail.to', 'span.mail.cc', 'span.mail.bcc'];
    }

    protected function searchLabel(): string
    {
        return 'Recipient';
    }

    protected function columns(): array
    {
        return [Ui::col('subject', 'Subject'), Ui::col('to', 'To')];
    }

    protected function cells(array $attributes, MatchedSpan $span, TraceSummary $trace): array
    {
        $subject = $this->str($attributes['mail.subject'] ?? null) ?? '(no subject)';

        return [
            'subject' => Ui::cell($subject, ['title' => $subject]),
            'to' => $this->recipients($attributes),
        ];
    }

    /**
     * The addresses on one line — To first, then how many in Cc and Bcc —
     * with the full lists on hover. Without them recorded, only the count
     * laravel-telemetry always keeps.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function recipients(array $attributes): array
    {
        $lists = [];

        foreach (self::FIELDS as $field => $label) {
            $value = $this->str($attributes[$field] ?? null);

            if ($value !== null) {
                $lists[$label] = array_map('trim', explode(',', $value));
            }
        }

        if ($lists === []) {
            $count = (int) $this->num($attributes['mail.recipients'] ?? null);

            return Ui::cell($count === 1 ? '1 recipient' : $count.' recipients', ['tone' => 'dim', 'raw' => $count]);
        }

        $line = implode(', ', $lists['To'] ?? []);

        foreach (['Cc', 'Bcc'] as $label) {
            if (isset($lists[$label])) {
                $line .= ($line !== '' ? ' · ' : '').strtolower($label).' '.count($lists[$label]);
            }
        }

        $title = implode("\n", array_map(static fn (string $label, array $addresses): string => $label.': '.implode(', ', $addresses), array_keys($lists), $lists));

        return Ui::cell($line, ['mono' => true, 'title' => $title]);
    }
}
