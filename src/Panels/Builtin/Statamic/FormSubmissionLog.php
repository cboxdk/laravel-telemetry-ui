<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Statamic;

use Cbox\TelemetryUi\Panels\Builtin\SpanLog;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;

/**
 * Each form submission — the POST to Statamic's `/!/forms/{form}` — with the
 * answer it got. A 422 is a submission that failed validation; a 5xx one that
 * never got stored.
 */
final class FormSubmissionLog extends SpanLog
{
    protected function prefix(): string
    {
        return 'forms';
    }

    protected function title(): string
    {
        return 'Submissions';
    }

    protected function subtitle(): string
    {
        return 'Each submission, newest first: the form, the answer it got, and how long it took';
    }

    protected function empty(): string
    {
        return 'No form submissions traced in this period.';
    }

    protected function conditions(): array
    {
        return [
            TraceCondition::token('kind', TraceOp::Eq, 'server'),
            TraceCondition::re('span.url.path', '.*/!/forms/.+'),
        ];
    }

    protected function attributes(): array
    {
        return ['span.url.path', 'span.http.response.status_code'];
    }

    protected function columns(): array
    {
        return [Ui::col('form', 'Form'), Ui::num('code', 'Answer')];
    }

    protected function cells(array $attributes, MatchedSpan $span, TraceSummary $trace): array
    {
        $path = $this->str($attributes['url.path'] ?? null) ?? '';
        $code = $this->str($attributes['http.response.status_code'] ?? null);
        $form = preg_match('~/!/forms/([^/?]+)~', $path, $m) === 1 ? $m[1] : $path;

        return [
            'form' => Ui::cell($form !== '' ? $form : '—', ['mono' => true]),
            'code' => $code === null
                ? Ui::cell('—', ['tone' => 'dim'])
                : Ui::cell($code, ['mono' => true, 'raw' => (int) $code, 'tone' => (int) $code >= 500 ? 'danger' : ((int) $code >= 400 ? 'warn' : null)]),
        ];
    }
}
