<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Concerns;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Compilers\TraceqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\LineFilter;
use Cbox\TelemetryUi\Queries\Ir\LineOp;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Queries\Results\LogEntry;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;

/**
 * Individual model calls, from the best source there is:
 *
 * 1. **Usage records** — with `instrument.ai_usage_events` on, the emitter
 *    writes one unsampled `gen_ai.client.inference.operation.details` log
 *    record per call, carrying its gen_ai.* usage, `user.id`, every
 *    `Telemetry::context()` dimension and the ids of its span. Read from
 *    Loki, these are exact for as long as the period fits in one read.
 * 2. **Spans** — otherwise the `chat` / `embeddings` spans, which carry the
 *    same attributes. A Tempo search returns the most recent traces and a
 *    few matching spans from each, so this is a recent sample, and every
 *    caller says so.
 *
 * One shape for both, so a panel aggregates without caring which it got.
 *
 * @phpstan-type AiCall array{ts: int, traceId: string, spanId: string, operation: string, provider: string, model: string, input: float, output: float, cacheRead: float, cacheWrite: float, reasoning: float, cost: float|null, finish: string, agent: string, error: string, origin: string, attributes: array<string, string>}
 * @phpstan-type AiCallRead array{calls: list<AiCall>, source: 'records'|'spans', exact: bool, limit: int}
 */
trait ReadsAiCalls
{
    /** The log body the emitter writes for one model call. */
    protected const AI_USAGE_EVENT = 'gen_ai.client.inference.operation.details';

    /** Usage records per read: past this, the read is the most recent slice. */
    protected int $aiRecordLimit = 5000;

    /** Traces per span search. */
    protected int $aiTraceLimit = 100;

    /**
     * The usage attributes every read asks for, as span attributes.
     *
     * @var list<string>
     */
    private const AI_CALL_FIELDS = [
        'gen_ai.operation.name',
        'gen_ai.provider.name',
        'gen_ai.request.model',
        'gen_ai.usage.input_tokens',
        'gen_ai.usage.output_tokens',
        'gen_ai.usage.cache_read.input_tokens',
        'gen_ai.usage.cache_creation.input_tokens',
        'gen_ai.usage.reasoning.output_tokens',
        'gen_ai.usage.cost',
        'gen_ai.response.finish_reasons',
        'gen_ai.agent.name',
        'error.type',
        'user.id',
    ];

    /**
     * Model calls in the period, optionally only those whose `$attribute`
     * equals `$value`, and with `$attribute` read onto every call. Records
     * when the app writes them, spans otherwise; `client.address` lives on
     * the request's root span only, so it always reads spans.
     *
     * @return AiCallRead
     *
     * @throws SourceException when the span search fails (a failed record read
     *                         falls back to spans instead)
     */
    protected function aiCalls(?string $attribute = null, ?string $value = null): array
    {
        if ($attribute !== 'client.address') {
            try {
                $calls = $this->aiCallsFromRecords($attribute, $value);

                if ($calls !== []) {
                    return ['calls' => $calls, 'source' => 'records', 'exact' => count($calls) < $this->aiRecordLimit, 'limit' => $this->aiRecordLimit];
                }
            } catch (SourceException) {
                // No logs backend, or it failed: the spans still answer.
            }
        }

        return ['calls' => $this->aiCallsFromSpans($attribute, $value), 'source' => 'spans', 'exact' => false, 'limit' => $this->aiTraceLimit];
    }

    /**
     * @return list<AiCall>
     *
     * @throws SourceException
     */
    private function aiCallsFromRecords(?string $attribute, ?string $value): array
    {
        [$start, $end] = $this->range();

        $query = $this->logSelector()->pipe(new LineFilter(LineOp::Contains, self::AI_USAGE_EVENT));

        if ($attribute !== null && $value !== null) {
            $query = $query->whereLabel(str_replace(['.', '-'], '_', $attribute), MatchOp::Eq, $value);
        }

        $calls = [];

        foreach ($this->logs()->query($query, $start, $end, limit: $this->aiRecordLimit) as $entry) {
            if (trim($entry->line) === self::AI_USAGE_EVENT) {
                $calls[] = self::aiCallFromRecord($entry);
            }
        }

        return $calls;
    }

    /**
     * @return list<AiCall>
     *
     * @throws SourceException
     */
    private function aiCallsFromSpans(?string $attribute, ?string $value): array
    {
        [$start, $end] = $this->range();

        $operation = TraceCondition::re('span.gen_ai.operation.name', 'chat|embeddings');
        $fields = array_map(static fn (string $field): string => 'span.'.$field, self::AI_CALL_FIELDS);

        // The client address is on the request's root span, not on the call:
        // read it from the root spans above the calls, and join on the trace.
        if ($attribute === 'client.address') {
            $roots = $this->aiRootAttribute($attribute, $value, $start, $end);
            $calls = [];

            foreach ($this->traces()->search($this->traceQuery($operation)->select(...$fields), $start, $end, limit: $this->aiTraceLimit) as $summary) {
                if (! isset($roots[$summary->traceId])) {
                    continue;
                }

                foreach ($summary->matchedSpans as $span) {
                    $call = self::aiCallFromSpan($span, $summary);
                    $call['attributes'][$attribute] = $roots[$summary->traceId];
                    $calls[] = $call;
                }
            }

            return $calls;
        }

        $conditions = [$operation];

        if ($attribute !== null) {
            $conditions[] = $value !== null
                ? TraceCondition::eq('span.'.$attribute, $value)
                : TraceCondition::nil('span.'.$attribute);

            if (! in_array($attribute, self::AI_CALL_FIELDS, true)) {
                $fields[] = 'span.'.$attribute;
            }
        }

        $calls = [];

        foreach ($this->traces()->search($this->traceQuery(...$conditions)->select(...$fields), $start, $end, limit: $this->aiTraceLimit) as $summary) {
            foreach ($summary->matchedSpans as $span) {
                $calls[] = self::aiCallFromSpan($span, $summary);
            }
        }

        return $calls;
    }

    /**
     * Trace id → the root-span value of `$attribute`, for the traces that
     * hold a model call: `{call} << {root with attribute}`.
     *
     * @return array<string, string>
     *
     * @throws SourceException
     */
    private function aiRootAttribute(string $attribute, ?string $value, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $compiler = new TraceqlCompiler;
        $calls = new TraceQuery([TraceCondition::re('span.gen_ai.operation.name', 'chat|embeddings')]);
        $roots = $this->traceQuery($value !== null ? TraceCondition::eq('span.'.$attribute, $value) : TraceCondition::nil('span.'.$attribute))
            ->select('span.'.$attribute);

        $byTrace = [];

        foreach ($this->traces()->search(TraceQuery::raw($compiler->compile($calls).' << '.$compiler->compile($roots)), $start, $end, limit: $this->aiTraceLimit) as $summary) {
            foreach ($summary->matchedSpans as $span) {
                $found = $span->attributes[$attribute] ?? null;

                if (is_scalar($found) && (string) $found !== '') {
                    $byTrace[$summary->traceId] = (string) $found;
                }
            }
        }

        return $byTrace;
    }

    /**
     * @return AiCall
     */
    private static function aiCallFromRecord(LogEntry $entry): array
    {
        $attributes = [];

        foreach ($entry->labels as $key => $label) {
            $attributes[$key] = $label;
        }

        $get = static function (string $key) use ($entry): string {
            return $entry->labels[$key] ?? $entry->labels[str_replace(['.', '-'], '_', $key)] ?? '';
        };

        return self::aiCall($get, intdiv($entry->timestampNano, 1_000_000), $get('trace_id'), $get('span_id'), '', $attributes);
    }

    /**
     * @return AiCall
     */
    private static function aiCallFromSpan(MatchedSpan $span, TraceSummary $summary): array
    {
        $attributes = [];

        foreach ($span->attributes as $key => $attribute) {
            if (is_scalar($attribute)) {
                $attributes[(string) $key] = (string) $attribute;
            }
        }

        $get = static fn (string $key): string => $attributes[$key] ?? '';
        $call = self::aiCall($get, $span->startNano > 0 ? intdiv($span->startNano, 1_000_000) : $summary->startedAt->getTimestamp() * 1000, $summary->traceId, $span->spanId, $summary->rootTraceName, $attributes);

        if ($call['operation'] === '') {
            $call['operation'] = explode(' ', $span->name, 2)[0];
        }

        return $call;
    }

    /**
     * @param  callable(string): string  $get
     * @param  array<string, string>  $attributes
     * @return AiCall
     */
    private static function aiCall(callable $get, int $ts, string $traceId, string $spanId, string $origin, array $attributes): array
    {
        $number = static fn (string $key): float => is_numeric($get($key)) ? (float) $get($key) : 0.0;
        $cost = $get('gen_ai.usage.cost');
        $model = $get('gen_ai.request.model');

        return [
            'ts' => $ts,
            'traceId' => $traceId,
            'spanId' => $spanId,
            'operation' => $get('gen_ai.operation.name'),
            'provider' => $get('gen_ai.provider.name'),
            'model' => $model !== '' ? $model : $get('gen_ai.response.model'),
            'input' => $number('gen_ai.usage.input_tokens'),
            'output' => $number('gen_ai.usage.output_tokens'),
            'cacheRead' => $number('gen_ai.usage.cache_read.input_tokens'),
            'cacheWrite' => $number('gen_ai.usage.cache_creation.input_tokens'),
            'reasoning' => $number('gen_ai.usage.reasoning.output_tokens'),
            'cost' => is_numeric($cost) ? (float) $cost : null,
            'finish' => $get('gen_ai.response.finish_reasons'),
            'agent' => $get('gen_ai.agent.name'),
            'error' => $get('error.type'),
            'origin' => $origin,
            'attributes' => $attributes,
        ];
    }

    /**
     * The value of `$attribute` on a call, whichever spelling its source used.
     *
     * @param  AiCall  $call
     */
    protected static function aiCallValue(array $call, string $attribute): string
    {
        return $call['attributes'][$attribute] ?? $call['attributes'][str_replace(['.', '-'], '_', $attribute)] ?? '';
    }

    /**
     * The note that says where a set of calls came from, and how exact it is.
     *
     * @param  AiCallRead  $read
     */
    protected static function aiSourceNote(array $read): string
    {
        return match (true) {
            $read['source'] === 'records' && $read['exact'] => 'Exact: every model call in this period, from the usage records (instrument.ai_usage_events).',
            $read['source'] === 'records' => 'From the most recent '.number_format($read['limit']).' usage records in this period; narrow the range for exact totals.',
            default => 'From the most recent '.$read['limit'].' traces with model calls (Tempo search returns a few calls per trace). Turn on instrument.ai_usage_events for exact totals.',
        };
    }
}
