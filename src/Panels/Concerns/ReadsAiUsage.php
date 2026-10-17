<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Concerns;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Builtin\Detail\ScopesToAiAgent;
use Cbox\TelemetryUi\Panels\Builtin\Detail\ScopesToAiModel;
use Cbox\TelemetryUi\Queries\Compilers\TraceqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use DateTimeImmutable;

/**
 * The laravel/ai instrumentation of cboxdk/laravel-telemetry, as the AI page
 * reads it: the OTel GenAI client histograms plus the core's own cost counter.
 *
 * - `gen_ai_client_operation_duration_seconds` — one observation per model
 *   call, labelled `gen_ai_operation_name` (chat / embeddings),
 *   `gen_ai_provider_name`, `gen_ai_request_model`, and `error_type` on a
 *   failed call only;
 * - `gen_ai_client_token_usage` — tokens per call, the same labels (no
 *   `error_type`) plus `gen_ai_token_type` (input / output);
 * - `gen_ai_client_cost_total` — what the calls cost, priced by the app from
 *   `telemetry.instrument.ai_pricing.models`. It only exists for a model with
 *   a configured price: a model that used tokens and has no cost series is
 *   unpriced, not free.
 *
 * And the spans: `invoke_agent {agent}` for an agent run, with `chat {model}`
 * and `execute_tool {tool}` children, and `embeddings {model}`.
 */
trait ReadsAiUsage
{
    protected const AI_DURATION = 'gen_ai_client_operation_duration_seconds';

    protected const AI_TOKENS = 'gen_ai_client_token_usage';

    protected const AI_COST = 'gen_ai_client_cost_total';

    protected const AI_PRICING_HINT = 'No model prices configured. Set telemetry.instrument.ai_pricing.models in the app to see what calls cost.';

    /**
     * The currency the cost counter is in. The emitter does not label it, so
     * it is read from the same config the emitter prices with (the dashboard
     * usually runs in the app it watches); USD when unset.
     */
    protected function aiCurrency(): string
    {
        $currency = config('telemetry.instrument.ai_pricing.currency');

        return is_string($currency) && trim($currency) !== '' ? strtoupper(trim($currency)) : 'USD';
    }

    /**
     * One row key per provider + model.
     *
     * @param  array<string, string>  $labels
     */
    protected static function aiModelKey(array $labels): string
    {
        return ($labels['gen_ai_provider_name'] ?? '').'|'.($labels['gen_ai_request_model'] ?? '');
    }

    /**
     * The model a detail page is scoped to (`?model=`), or '' for every
     * model. See {@see ScopesToAiModel}.
     */
    protected function scopedModel(): string
    {
        return '';
    }

    /**
     * The agent a detail page is scoped to (`?agent=`), or '' for every
     * agent. See {@see ScopesToAiAgent}.
     */
    protected function scopedAgent(): string
    {
        return '';
    }

    /**
     * `gen_ai.response.finish_reasons` arrives as a JSON array string
     * (`["stop"]`); a plain string is taken as it is.
     */
    protected static function finishReasons(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return $value;
        }

        return implode(', ', array_filter(array_map(
            static fn (mixed $reason): string => is_scalar($reason) ? (string) $reason : '',
            $decoded,
        ), static fn (string $reason): bool => $reason !== ''));
    }

    /**
     * An `error.type` short enough for a status badge: the class basename
     * without its `Exception` suffix (`Laravel\Ai\…\RateLimitExceededException`
     * → `RateLimitExceeded`). The full type is in the trace.
     */
    protected static function shortErrorType(string $type): string
    {
        $base = str_contains($type, '\\') ? substr($type, (int) strrpos($type, '\\') + 1) : $type;
        $short = (string) preg_replace('/Exception$/', '', $base);

        return $short !== '' ? $short : $base;
    }

    /**
     * Whether a finish reason means the model stopped on its own (or to call
     * a tool), rather than being cut off or filtered.
     */
    protected static function finishedNormally(string $reason): bool
    {
        return in_array($reason, ['', 'stop', 'end_turn', 'tool_calls', 'tool_use'], true);
    }

    /**
     * The tools run inside the traces holding the spans `$parent` matches:
     * trace id → tool names, in the order Tempo returned them. One structural
     * search (`{parent} >> {execute_tool}`), so a run list can say which tools
     * a run used without fetching every trace.
     *
     * Tempo returns at most a few matched spans per trace, so a run that
     * called many tools lists the first few; the waterfall has all of them.
     * Null when the search failed: tools are an extra, never the reason a
     * list of runs does not load.
     *
     * @return array<string, list<string>>|null
     */
    protected function toolsByTrace(TraceQuery $parent, DateTimeImmutable $start, DateTimeImmutable $end, int $limit): ?array
    {
        $compiler = new TraceqlCompiler;
        $tools = $this->traceQuery(TraceCondition::eq('span.gen_ai.operation.name', 'execute_tool'))->select('span.gen_ai.tool.name');

        try {
            $results = $this->traces()->search(
                TraceQuery::raw($compiler->compile(new TraceQuery($parent->conditions)).' >> '.$compiler->compile($tools)),
                $start,
                $end,
                limit: $limit,
            );
        } catch (SourceException) {
            return null;
        }

        $byTrace = [];

        foreach ($results as $summary) {
            foreach ($summary->matchedSpans as $span) {
                $name = $span->attributes['gen_ai.tool.name'] ?? null;
                $byTrace[$summary->traceId][] = is_string($name) && $name !== ''
                    ? $name
                    : (str_starts_with($span->name, 'execute_tool ') ? substr($span->name, 13) : $span->name);
            }
        }

        return $byTrace;
    }
}
