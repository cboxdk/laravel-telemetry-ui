<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Panels\ListsRows;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use Cbox\TelemetryUi\Support\DisplayTime;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Support\Str;

/**
 * The row-level half of a page: one row per span of a kind — a mail sent, a
 * job run, a command — newest first, each opening its trace. Aggregates say
 * something is wrong; these rows are where you find which one, when, and what
 * it was part of.
 *
 * A subclass says which spans ({@see conditions()}), which attributes to
 * fetch ({@see attributes()}) and how to show them ({@see columns()},
 * {@see cells()}). The base adds when it happened, what it ran inside (the
 * trace's root: the request, job or command), how it ended and how long it
 * took, plus "failed only" and "slowest first" filters.
 */
abstract class SpanLog extends Panel implements ListsRows
{
    use CoercesAttributes;

    protected const LIMIT = 50;

    public static function span(): int
    {
        return 2;
    }

    /** Prefix for this log's own query parameters, unique on its page. */
    abstract protected function prefix(): string;

    abstract protected function title(): string;

    abstract protected function subtitle(): string;

    /** What nothing to show means, in this log's words. */
    abstract protected function empty(): string;

    /**
     * Which spans: on top of the scope.
     *
     * @return list<TraceCondition>
     */
    abstract protected function conditions(): array;

    /**
     * The span attributes to fetch, as TraceQL fields (`span.mail.subject`).
     *
     * @return list<string>
     */
    abstract protected function attributes(): array;

    /**
     * The columns between When and From.
     *
     * @return list<array{key: string, label: string, align?: string, width?: string}>
     */
    abstract protected function columns(): array;

    /**
     * Those columns' cells for one span. `$attributes` are keyed without the
     * `span.` prefix, as Tempo returns them.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, array<string, mixed>>
     */
    abstract protected function cells(array $attributes, MatchedSpan $span, TraceSummary $trace): array;

    /**
     * Fields a search box matches, any of them, case-insensitively
     * (`span.mail.to`). None: no search box.
     *
     * @return list<string>
     */
    protected function searchFields(): array
    {
        return [];
    }

    protected function searchLabel(): string
    {
        return 'Search';
    }

    /** Above this, a duration reads as slow. */
    protected function slowMs(): float
    {
        return 1000.0;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();
        $failedOnly = $this->param('status') === 'error';
        $slowest = $this->param('sort') === 'slow';
        $search = trim($this->param('q'));
        $fields = $this->searchFields();
        $matching = [];

        if ($search !== '' && $fields !== []) {
            $pattern = '(?i).*'.preg_quote($search, null).'.*';
            $matching[] = TraceCondition::any(...array_map(static fn (string $field): TraceCondition => TraceCondition::re($field, $pattern), $fields));
        }

        $found = [];
        $error = null;

        try {
            $query = $this->traceQuery(...$this->conditions(), ...$matching, ...($failedOnly ? [TraceCondition::token('status', TraceOp::Eq, 'error')] : []))
                ->select('status', 'statusMessage', 'span.error.type', ...$this->attributes());

            foreach ($this->traces()->search($query, $start, $end, limit: static::LIMIT) as $trace) {
                foreach ($trace->matchedSpans as $span) {
                    $found[] = [$span, $trace];
                }
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        usort($found, $slowest
            ? static fn (array $a, array $b): int => $b[0]->durationMs <=> $a[0]->durationMs
            : static fn (array $a, array $b): int => $b[0]->startNano <=> $a[0]->startNano);

        $rows = array_map(fn (array $hit): array => $this->row(...$hit), array_slice($found, 0, static::LIMIT));

        return Ui::table($this->title(), [
            Ui::col('when', 'When'),
            ...$this->columns(),
            Ui::col('from', 'From'),
            Ui::col('outcome', 'Status'),
            Ui::num('duration', 'Duration'),
        ], $rows, [
            'subtitle' => $this->subtitle(),
            'span' => static::span(),
            'error' => $error,
            'empty' => match (true) {
                $matching !== [] => 'Nothing matching "'.$search.'" in this period.',
                $failedOnly => 'No failures in this period.',
                default => $this->empty(),
            },
            'note' => 'Sampled from the most recent matching traces. Click a row for the trace it was part of.',
            'controls' => [
                ...($fields !== [] ? [Ui::search($this->paramName('q'), $this->searchLabel(), $search, $this->searchLabel().'…')] : []),
                Ui::select($this->paramName('status'), 'Show', $failedOnly ? 'error' : '', [
                    ['value' => '', 'label' => 'All'],
                    ['value' => 'error', 'label' => 'Failed only'],
                ]),
                Ui::select($this->paramName('sort'), 'Sort by', $slowest ? 'slow' : 'recent', [
                    ['value' => 'recent', 'label' => 'Most recent'],
                    ['value' => 'slow', 'label' => 'Slowest'],
                ]),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MatchedSpan $span, TraceSummary $trace): array
    {
        $link = Ui::trace($trace->traceId, $trace->startedAt);
        $at = $span->startNano > 0 ? intdiv($span->startNano, 1_000_000_000) : $trace->startedAt->getTimestamp();
        $status = $this->str($span->attributes['status'] ?? null);

        return [
            'when' => Ui::cell(DisplayTime::at($at, 'H:i:s'), ['raw' => $at, 'mono' => true]),
            ...$this->cells($span->attributes, $span, $trace),
            // One line per row, like every other list: the detail is on hover.
            'from' => Ui::cell($trace->rootTraceName !== '' ? $trace->rootTraceName : '(unnamed)', array_filter([
                'link' => $link,
                'title' => $trace->rootServiceName !== '' ? $trace->rootServiceName : null,
            ])),
            'outcome' => $status === 'error'
                ? Ui::cell('failed', array_filter(['badge' => 'failed', 'tone' => 'danger', 'title' => $this->why($span->attributes)]))
                : Ui::cell($status === 'ok' ? 'ok' : '—', ['tone' => $status === 'ok' ? 'ok' : 'dim']),
            'duration' => Ui::cell(Format::ms($span->durationMs), [
                'raw' => $span->durationMs,
                'mono' => true,
                'tone' => $span->durationMs > $this->slowMs() ? 'warn' : null,
            ]),
            '_link' => $link,
        ];
    }

    /**
     * Why a span failed, in a line: the exception's class and message (the
     * span's status message), when the emitter recorded them.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function why(array $attributes): ?string
    {
        $type = $this->str($attributes['error.type'] ?? null);
        $message = $this->str($attributes['statusMessage'] ?? null);
        $why = implode(': ', array_filter([$type !== null ? class_basename($type) : null, $message !== null ? Str::limit($message, 140) : null]));

        return $why !== '' ? $why : null;
    }

    private function paramName(string $name): string
    {
        return $this->prefix().'_'.$name;
    }

    private function param(string $name): string
    {
        return (string) ($this->scope->params[$this->paramName($name)] ?? '');
    }
}
