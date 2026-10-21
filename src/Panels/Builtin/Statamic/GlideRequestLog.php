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
 * Each image request that reached PHP — Glide's route (`/img/…`, Statamic's
 * default) — slowest first is where a generation hurts. An image served from
 * Glide's cache by the web server never reaches PHP, so it is not here.
 */
final class GlideRequestLog extends SpanLog
{
    protected function prefix(): string
    {
        return 'glide';
    }

    protected function title(): string
    {
        return 'Image requests';
    }

    protected function subtitle(): string
    {
        return 'Each image request PHP served, newest first: the image, the answer, and the time it took';
    }

    protected function empty(): string
    {
        return 'No image requests reached PHP in this period. This reads Glide\'s default route, /img/.';
    }

    protected function conditions(): array
    {
        return [
            TraceCondition::token('kind', TraceOp::Eq, 'server'),
            TraceCondition::re('span.url.path', '/img/.+'),
        ];
    }

    protected function attributes(): array
    {
        return ['span.url.path', 'span.http.response.status_code'];
    }

    protected function columns(): array
    {
        return [Ui::col('image', 'Image'), Ui::num('code', 'Answer')];
    }

    protected function slowMs(): float
    {
        return 500.0;
    }

    protected function cells(array $attributes, MatchedSpan $span, TraceSummary $trace): array
    {
        $code = $this->str($attributes['http.response.status_code'] ?? null);

        return [
            'image' => Ui::cell($this->str($attributes['url.path'] ?? null) ?? '—', ['mono' => true]),
            'code' => $code === null
                ? Ui::cell('—', ['tone' => 'dim'])
                : Ui::cell($code, ['mono' => true, 'raw' => (int) $code, 'tone' => (int) $code >= 400 ? 'danger' : null]),
        ];
    }
}
