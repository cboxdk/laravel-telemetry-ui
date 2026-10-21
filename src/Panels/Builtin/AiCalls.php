<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Concerns\CoercesAttributes;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiUsage;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Queries\Compilers\TraceqlCompiler;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Support\DisplayTime;
use Cbox\TelemetryUi\Support\Format;

/**
 * Individual model calls — the `chat` and `embeddings` spans — with what each
 * one used and cost, and how it ended. The row-level view behind the
 * aggregates: one row is one request to a provider, and opens its trace.
 */
class AiCalls extends Panel
{
    use CoercesAttributes;
    use ReadsAiUsage;

    #[Param('ai_calls_sort')]
    public string $sort = 'recent';

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();
        $sort = in_array($this->sort, ['duration', 'cost'], true) ? $this->sort : 'recent';
        $currency = $this->aiCurrency();
        $model = $this->scopedModel();
        [$dimension, $value] = $this->scopedDimension() ?? [null, null];

        $rows = [];
        $error = null;

        try {
            $query = $this->traceQuery(...array_values(array_filter([
                TraceCondition::re('span.gen_ai.operation.name', 'chat|embeddings'),
                $model !== '' ? TraceCondition::eq('span.gen_ai.request.model', $model) : null,
            ])))->select(
                'span.gen_ai.operation.name',
                'span.gen_ai.request.model',
                'span.gen_ai.provider.name',
                'span.gen_ai.usage.input_tokens',
                'span.gen_ai.usage.output_tokens',
                'span.gen_ai.usage.cache_read.input_tokens',
                'span.gen_ai.usage.cache_creation.input_tokens',
                'span.gen_ai.usage.reasoning.output_tokens',
                'span.gen_ai.usage.cost',
                'span.gen_ai.response.finish_reasons',
                'span.error.type',
            );

            if ($dimension === 'client.address') {
                // The client address is on the request's root span, above the
                // call: `{root} >> {call}`.
                $compiler = new TraceqlCompiler;
                $query = TraceQuery::raw($compiler->compile($this->traceQuery(TraceCondition::eq('span.client.address', (string) $value))).' >> '.$compiler->compile($query));
            } elseif ($dimension !== null) {
                $query = $query->where(TraceCondition::eq('span.'.$dimension, (string) $value));
            }

            foreach ($this->traces()->search($query, $start, $end, limit: 50) as $summary) {
                foreach ($summary->matchedSpans as $span) {
                    $attributes = $span->attributes;
                    $cost = $attributes['gen_ai.usage.cost'] ?? null;
                    [$operation] = explode(' ', $span->name, 2);

                    $rows[] = [
                        'operation' => $this->str($attributes['gen_ai.operation.name'] ?? null) ?? $operation,
                        'model' => $this->str($attributes['gen_ai.request.model'] ?? null) ?? '',
                        'provider' => $this->str($attributes['gen_ai.provider.name'] ?? null) ?? '',
                        'input' => $this->num($attributes['gen_ai.usage.input_tokens'] ?? null),
                        'output' => $this->num($attributes['gen_ai.usage.output_tokens'] ?? null),
                        'cacheRead' => $this->num($attributes['gen_ai.usage.cache_read.input_tokens'] ?? null),
                        'cacheWrite' => $this->num($attributes['gen_ai.usage.cache_creation.input_tokens'] ?? null),
                        'reasoning' => $this->num($attributes['gen_ai.usage.reasoning.output_tokens'] ?? null),
                        'cost' => is_numeric($cost) ? (float) $cost : null,
                        'finish' => self::finishReasons($attributes['gen_ai.response.finish_reasons'] ?? null),
                        'error' => $this->str($attributes['error.type'] ?? null) ?? '',
                        'durationMs' => $span->durationMs,
                        'startNano' => $span->startNano,
                        'origin' => $summary->rootTraceName,
                        'traceId' => $summary->traceId,
                        'startedAt' => $summary->startedAt,
                    ];
                }
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        usort($rows, match ($sort) {
            'duration' => static fn (array $a, array $b): int => $b['durationMs'] <=> $a['durationMs'],
            'cost' => static fn (array $a, array $b): int => [$b['cost'] ?? -1.0, $b['durationMs']] <=> [$a['cost'] ?? -1.0, $a['durationMs']],
            default => static fn (array $a, array $b): int => $b['startNano'] <=> $a['startNano'],
        });

        $table = array_map(static function (array $row) use ($currency, $model): array {
            $trace = Ui::trace($row['traceId'], $row['startedAt']);
            $at = $row['startNano'] > 0 ? intdiv($row['startNano'], 1_000_000_000) : $row['startedAt']->getTimestamp();
            // Chat is the rule, so only another operation is named — under the
            // call rather than in a column of its own, which kept the table
            // just short of turning into cards at laptop width.
            $context = implode(' · ', array_filter([$row['operation'] !== 'chat' ? $row['operation'] : '', $row['origin']]));

            return [
                'when' => Ui::cell(DisplayTime::at($at, 'H:i:s'), ['raw' => $at, 'mono' => true]),
                // On a model's own page the model is the page; say where the
                // call came from instead.
                'call' => $model === ''
                    ? Ui::cell($row['model'] !== '' ? $row['model'] : '(unknown)', [
                        'mono' => true,
                        'link' => $row['model'] !== '' ? Ui::entity('ai-model', $row['model']) : $trace,
                        ...($context !== '' ? ['sub' => $context] : []),
                    ])
                    : Ui::cell($row['origin'] !== '' ? $row['origin'] : '(unnamed)', [
                        'link' => $trace,
                        ...($row['operation'] !== 'chat' ? ['sub' => $row['operation']] : []),
                    ]),
                'tokens' => Ui::cell(Format::count($row['input']).' / '.Format::count($row['output']), [
                    'raw' => $row['input'] + $row['output'],
                    // Cache and reasoning tokens ride under the totals they are
                    // part of, rather than in columns that push the table into
                    // cards on a laptop.
                    ...(($detail = implode(' · ', array_filter([
                        $row['cacheRead'] > 0 ? Format::count($row['cacheRead']).' cached' : null,
                        $row['cacheWrite'] > 0 ? Format::count($row['cacheWrite']).' cache write' : null,
                        $row['reasoning'] > 0 ? Format::count($row['reasoning']).' reasoning' : null,
                    ]))) !== '' ? ['sub' => $detail] : []),
                ]),
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

        return Ui::table(match (true) {
            $model !== '' => 'Calls',
            $dimension !== null => 'Model calls',
            default => 'Recent model calls',
        }, [
            Ui::col('when', 'When'),
            Ui::col('call', $model === '' ? 'Model' : 'Origin'),
            Ui::num('tokens', 'Tokens in / out'),
            Ui::num('cost', 'Cost'),
            Ui::col('status', 'Finish'),
            Ui::num('duration', 'Duration'),
        ], $table, [
            'subtitle' => 'Individual chat and embeddings calls sampled from traces. Click a row for the waterfall',
            'error' => $error,
            'empty' => match (true) {
                $model !== '' => 'No calls to this model traced in this period.',
                $dimension !== null => 'No model calls traced for '.$dimension.' = '.$value.' in this period.',
                default => 'No model calls traced in this period.',
            },
            'note' => 'Sampled from the most recent matching traces (Tempo search). Cost is set only on calls to a priced model.',
            'controls' => [Ui::select('ai_calls_sort', 'Sort by', $sort, [
                ['value' => 'recent', 'label' => 'Most recent'],
                ['value' => 'duration', 'label' => 'Duration'],
                ['value' => 'cost', 'label' => 'Cost'],
            ])],
        ]);
    }
}
