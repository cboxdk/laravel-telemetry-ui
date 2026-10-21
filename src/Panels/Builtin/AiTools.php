<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiUsage;
use Cbox\TelemetryUi\Panels\ListsRows;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Compilers\TraceqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Support\Format;
use DateTimeImmutable;

/**
 * The tools agents call, from a sample of `execute_tool` spans: how often
 * each ran, how long it took, and the slowest run's trace.
 */
class AiTools extends Panel implements ListsRows
{
    use CoercesAttributes;
    use ReadsAiUsage;

    public function data(): array
    {
        [$start, $end] = $this->range();

        /** @var array<string, array{tool: string, runs: int, total: float, max: float, traceId: string, startedAt: DateTimeImmutable}> $tools */
        $tools = [];
        $error = null;

        try {
            $query = $this->traceQuery(
                TraceCondition::eq('span.gen_ai.operation.name', 'execute_tool'),
            )->select('span.gen_ai.tool.name');

            // A tool span does not name its agent, so one agent's tools are
            // the tool spans below its runs: `{run} >> {execute_tool}`.
            if (($agent = $this->scopedAgent()) !== '') {
                $compiler = new TraceqlCompiler;
                $runs = $this->traceQuery(
                    TraceCondition::eq('span.gen_ai.operation.name', 'invoke_agent'),
                    TraceCondition::eq('span.gen_ai.agent.name', $agent),
                );
                $query = TraceQuery::raw($compiler->compile($runs).' >> '.$compiler->compile($query));
            }

            foreach ($this->traces()->search($query, $start, $end, limit: 100) as $summary) {
                foreach ($summary->matchedSpans as $span) {
                    $tool = $this->str($span->attributes['gen_ai.tool.name'] ?? null)
                        ?? (str_starts_with($span->name, 'execute_tool ') ? substr($span->name, 13) : $span->name);

                    $entry = $tools[$tool] ?? ['tool' => $tool, 'runs' => 0, 'total' => 0.0, 'max' => -1.0, 'traceId' => $summary->traceId, 'startedAt' => $summary->startedAt];
                    $entry['runs']++;
                    $entry['total'] += $span->durationMs;

                    if ($span->durationMs > $entry['max']) {
                        $entry['max'] = $span->durationMs;
                        $entry['traceId'] = $summary->traceId;
                        $entry['startedAt'] = $summary->startedAt;
                    }

                    $tools[$tool] = $entry;
                }
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        $tools = array_values($tools);

        usort($tools, static fn (array $a, array $b): int => [$b['runs'], $b['total']] <=> [$a['runs'], $a['total']]);

        $rows = array_map(static fn (array $tool): array => [
            'tool' => Ui::cell($tool['tool'], ['mono' => true]),
            'runs' => Ui::cell(Format::count($tool['runs']), ['raw' => $tool['runs']]),
            'avg' => Ui::cell(Format::ms($tool['total'] / $tool['runs']), ['raw' => $tool['total'] / $tool['runs']]),
            'max' => Ui::cell(Format::ms($tool['max']), ['raw' => $tool['max'], 'link' => Ui::trace($tool['traceId'], $tool['startedAt'])]),
            '_link' => Ui::trace($tool['traceId'], $tool['startedAt']),
        ], array_slice($tools, 0, 25));

        return Ui::table('Agent tools', [
            Ui::col('tool', 'Tool'),
            Ui::num('runs', 'Runs'),
            Ui::num('avg', 'AVG'),
            Ui::num('max', 'Max'),
        ], $rows, [
            'subtitle' => 'execute_tool spans sampled from traces. A row opens its slowest run',
            'error' => $error,
            'empty' => $this->scopedAgent() === '' ? 'No tool calls traced in this period.' : 'This agent called no tools in this period.',
            'note' => 'Counts are from a sample of recent traces, not totals.',
        ]);
    }
}
