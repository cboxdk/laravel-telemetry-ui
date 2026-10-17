<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;

/**
 * A sample of one agent's runs, for the panels that summarise them. Agents
 * exist only on spans — there is no agent label on any metric — so an
 * agent's numbers are read from its most recent `invoke_agent` spans, and
 * the panels say so.
 */
trait ReadsAgentRuns
{
    /** Runs per sample: enough for a trend, cheap enough for one search. */
    private int $agentSample = 200;

    /**
     * @return list<array{at: int, durationMs: float, input: float, output: float, cost: float|null, failed: bool, model: string}>
     *
     * @throws SourceException
     */
    protected function agentRuns(): array
    {
        [$start, $end] = $this->range();

        $query = $this->traceQuery(
            TraceCondition::eq('span.gen_ai.operation.name', 'invoke_agent'),
            TraceCondition::eq('span.gen_ai.agent.name', $this->agent),
        )->select(
            'span.gen_ai.request.model',
            'span.gen_ai.usage.input_tokens',
            'span.gen_ai.usage.output_tokens',
            'span.gen_ai.usage.cost',
            'span.error.type',
        );

        $runs = [];

        foreach ($this->traces()->search($query, $start, $end, limit: $this->agentSample) as $summary) {
            foreach ($summary->matchedSpans as $span) {
                $cost = $span->attributes['gen_ai.usage.cost'] ?? null;
                $model = $span->attributes['gen_ai.request.model'] ?? null;
                $error = $span->attributes['error.type'] ?? null;

                $runs[] = [
                    'at' => $span->startNano > 0 ? intdiv($span->startNano, 1_000_000_000) : $summary->startedAt->getTimestamp(),
                    'durationMs' => $span->durationMs,
                    'input' => is_numeric($span->attributes['gen_ai.usage.input_tokens'] ?? null) ? (float) $span->attributes['gen_ai.usage.input_tokens'] : 0.0,
                    'output' => is_numeric($span->attributes['gen_ai.usage.output_tokens'] ?? null) ? (float) $span->attributes['gen_ai.usage.output_tokens'] : 0.0,
                    'cost' => is_numeric($cost) ? (float) $cost : null,
                    'failed' => is_scalar($error) && (string) $error !== '',
                    'model' => is_scalar($model) ? (string) $model : '',
                ];
            }
        }

        return $runs;
    }
}
