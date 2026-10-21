<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiUsage;
use Cbox\TelemetryUi\Panels\ListsRows;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Support\DisplayTime;
use Cbox\TelemetryUi\Support\Format;

/**
 * Agent runs: `invoke_agent` spans from Tempo with the agent, model, tokens,
 * cost, outcome and the tools each run called. Metrics can say what a model
 * costs; only a trace says which run and why.
 */
class AiAgentRuns extends Panel implements ListsRows
{
    use CoercesAttributes;
    use ReadsAiUsage;

    #[Param('ai_sort')]
    public string $sort = '';

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();
        $agent = $this->scopedAgent();
        // One agent's page is its run log, newest first; across agents the
        // question is which runs to look at, so slowest first.
        $sort = in_array($this->sort, ['recent', 'duration', 'cost'], true) ? $this->sort : ($agent === '' ? 'duration' : 'recent');
        $currency = $this->aiCurrency();

        $rows = [];
        $error = null;
        $tools = null;

        try {
            $query = $this->traceQuery(...array_values(array_filter([
                TraceCondition::eq('span.gen_ai.operation.name', 'invoke_agent'),
                $agent !== '' ? TraceCondition::eq('span.gen_ai.agent.name', $agent) : null,
            ])))->select(
                'span.gen_ai.agent.name',
                'span.gen_ai.request.model',
                'span.gen_ai.provider.name',
                'span.gen_ai.usage.input_tokens',
                'span.gen_ai.usage.output_tokens',
                'span.gen_ai.usage.cost',
                'span.gen_ai.response.finish_reasons',
                'span.error.type',
            );

            foreach ($this->traces()->search($query, $start, $end, limit: 50) as $summary) {
                foreach ($summary->matchedSpans as $span) {
                    $cost = $span->attributes['gen_ai.usage.cost'] ?? null;

                    $rows[] = [
                        'agent' => $this->str($span->attributes['gen_ai.agent.name'] ?? null)
                            ?? (str_starts_with($span->name, 'invoke_agent ') ? substr($span->name, 13) : $span->name),
                        'model' => $this->str($span->attributes['gen_ai.request.model'] ?? null) ?? '',
                        'provider' => $this->str($span->attributes['gen_ai.provider.name'] ?? null) ?? '',
                        'input' => $this->num($span->attributes['gen_ai.usage.input_tokens'] ?? null),
                        'output' => $this->num($span->attributes['gen_ai.usage.output_tokens'] ?? null),
                        'cost' => is_numeric($cost) ? (float) $cost : null,
                        'finish' => self::finishReasons($span->attributes['gen_ai.response.finish_reasons'] ?? null),
                        'error' => $this->str($span->attributes['error.type'] ?? null) ?? '',
                        'durationMs' => $span->durationMs,
                        'startNano' => $span->startNano,
                        'traceId' => $summary->traceId,
                        'startedAt' => $summary->startedAt,
                    ];
                }
            }

            if ($rows !== []) {
                $tools = $this->toolsByTrace($query, $start, $end, 50);
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        usort($rows, match ($sort) {
            'cost' => static fn (array $a, array $b): int => [$b['cost'] ?? -1.0, $b['durationMs']] <=> [$a['cost'] ?? -1.0, $a['durationMs']],
            'recent' => static fn (array $a, array $b): int => $b['startNano'] <=> $a['startNano'],
            default => static fn (array $a, array $b): int => $b['durationMs'] <=> $a['durationMs'],
        });

        $table = array_map(static function (array $row) use ($currency, $tools, $agent): array {
            $trace = Ui::trace($row['traceId'], $row['startedAt']);
            $used = $tools[$row['traceId']] ?? [];
            $toolsLine = match (true) {
                $tools === null => '',
                $used === [] => 'no tools',
                default => implode(', ', array_values(array_unique($used))),
            };

            return [
                // When it ran and the tools it called ride under the agent:
                // two columns fewer keep the run list a table on a laptop.
                'agent' => $agent === ''
                    ? Ui::cell($row['agent'], ['mono' => true, 'link' => Ui::entity('ai-agent', $row['agent']), 'sub' => implode(' · ', array_filter([DisplayTime::at($row['startedAt'], 'H:i:s'), $toolsLine]))])
                    : Ui::cell(DisplayTime::at($row['startedAt'], 'H:i:s'), ['raw' => $row['startedAt']->getTimestamp(), 'mono' => true, 'link' => $trace, ...($toolsLine !== '' ? ['sub' => $toolsLine] : [])]),
                'model' => $row['model'] !== ''
                    ? Ui::cell($row['model'], ['mono' => true, 'link' => Ui::entity('ai-model', $row['model'])])
                    : Ui::cell('—', ['tone' => 'dim']),
                'tokens' => Ui::cell(Format::count($row['input']).' / '.Format::count($row['output']), ['raw' => $row['input'] + $row['output']]),
                'cost' => $row['cost'] !== null
                    ? Ui::cell(Format::money($row['cost'], $currency), ['raw' => $row['cost']])
                    : Ui::cell('—', ['tone' => 'dim']),
                'status' => match (true) {
                    $row['error'] !== '' => Ui::cell(self::shortErrorType($row['error']), ['badge' => self::shortErrorType($row['error']), 'tone' => 'danger']),
                    $row['finish'] !== '' => Ui::cell($row['finish'], ['badge' => $row['finish'], 'tone' => self::finishedNormally($row['finish']) ? null : 'warn']),
                    default => Ui::cell('—', ['tone' => 'dim']),
                },
                'duration' => Ui::cell(Format::ms($row['durationMs']), ['raw' => $row['durationMs'], 'tone' => $row['durationMs'] > 10_000 ? 'warn' : null]),
                '_link' => $trace,
            ];
        }, array_slice($rows, 0, 50));

        $columns = array_values(array_filter([
            Ui::col('agent', $agent === '' ? 'Agent' : 'Started'),
            Ui::col('model', 'Model'),
            Ui::num('tokens', 'Tokens in / out'),
            Ui::num('cost', 'Cost'),
            Ui::col('status', 'Finish'),
            Ui::num('duration', 'Duration'),
        ]));

        return Ui::table(match (true) {
            $agent !== '' => 'Runs',
            $sort === 'cost' => 'Most expensive agent runs',
            $sort === 'recent' => 'Recent agent runs',
            default => 'Slowest agent runs',
        }, $columns, $table, [
            'subtitle' => 'invoke_agent spans sampled from traces. Click a row for the waterfall: model calls and tool runs',
            'error' => $error,
            'empty' => $agent === '' ? 'No agent runs traced in this period.' : 'No runs of this agent traced in this period.',
            'note' => 'Sampled from the most recent matching traces (Tempo search). Cost is set only for runs on a priced model; tools list the first few a trace ran.',
            'controls' => [Ui::select('ai_sort', 'Sort by', $sort, [
                ['value' => 'recent', 'label' => 'Most recent'],
                ['value' => 'duration', 'label' => 'Duration'],
                ['value' => 'cost', 'label' => 'Cost'],
            ])],
        ]);
    }
}
