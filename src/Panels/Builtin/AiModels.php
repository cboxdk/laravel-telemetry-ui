<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiUsage;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Model calls per provider + model: volume, tokens, cost, latency and errors.
 * A model with tokens and no cost series is marked unpriced rather than
 * shown as free.
 */
final class AiModels extends Panel
{
    use ReadsAiUsage;

    public static function span(): int
    {
        return 2;
    }

    /**
     * @return array{provider: string, model: string, operations: array<string, true>, calls: float, failed: float, input: float, output: float, cost: float|null, p95: float|null}
     */
    private static function row(string $provider, string $model): array
    {
        return [
            'provider' => $provider,
            'model' => $model,
            'operations' => [],
            'calls' => 0.0,
            'failed' => 0.0,
            'input' => 0.0,
            'output' => 0.0,
            'cost' => null,
            'p95' => null,
        ];
    }

    public function data(): array
    {
        $p = $this->promDuration();
        $currency = $this->aiCurrency();

        $rows = [];
        $error = null;
        $failure = null;
        // Whether the cost counter was read at all: when it failed, a model
        // without a cost is unknown, not unpriced.
        $costRead = false;

        try {
            foreach ($this->metrics()->query($this->metric(self::AI_DURATION.'_count')->increase($p)->sumBy('gen_ai_provider_name', 'gen_ai_request_model', 'gen_ai_operation_name')) as $sample) {
                $key = self::aiModelKey($sample->labels);
                $rows[$key] ??= self::row($sample->labels['gen_ai_provider_name'] ?? '', $sample->labels['gen_ai_request_model'] ?? '');
                $rows[$key]['calls'] += $sample->value;

                if (($operation = $sample->labels['gen_ai_operation_name'] ?? '') !== '') {
                    $rows[$key]['operations'][$operation] = true;
                }
            }

            foreach ($this->metrics()->query($this->metric(self::AI_DURATION.'_count', 'error_type!=""')->increase($p)->sumBy('gen_ai_provider_name', 'gen_ai_request_model')) as $sample) {
                $key = self::aiModelKey($sample->labels);
                $rows[$key] ??= self::row($sample->labels['gen_ai_provider_name'] ?? '', $sample->labels['gen_ai_request_model'] ?? '');
                $rows[$key]['failed'] += $sample->value;
            }

            foreach ($this->metrics()->query($this->metric(self::AI_TOKENS.'_sum')->increase($p)->sumBy('gen_ai_provider_name', 'gen_ai_request_model', 'gen_ai_token_type')) as $sample) {
                $key = self::aiModelKey($sample->labels);
                $rows[$key] ??= self::row($sample->labels['gen_ai_provider_name'] ?? '', $sample->labels['gen_ai_request_model'] ?? '');

                match ($sample->labels['gen_ai_token_type'] ?? '') {
                    'input' => $rows[$key]['input'] += $sample->value,
                    'output' => $rows[$key]['output'] += $sample->value,
                    default => null,
                };
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        // The extras: a table of calls and tokens still answers when the
        // cost counter or a period-wide quantile does not.
        if ($error === null) {
            $costs = $this->attempt(fn (): array => $this->metrics()->query($this->counterIncrease($this->metric(self::AI_COST)->sumBy('gen_ai_provider_name', 'gen_ai_request_model'))), $failure);
            $costRead = $costs !== null;

            foreach ($costs ?? [] as $sample) {
                $key = self::aiModelKey($sample->labels);

                if (isset($rows[$key])) {
                    $rows[$key]['cost'] = ($rows[$key]['cost'] ?? 0.0) + $sample->value;
                }
            }

            $latency = $this->attempt(fn (): array => $this->metrics()->query($this->metric(self::AI_DURATION.'_bucket')->quantile(0.95, $p, 'gen_ai_provider_name', 'gen_ai_request_model')), $failure) ?? [];

            foreach ($latency as $sample) {
                $key = self::aiModelKey($sample->labels);

                if (isset($rows[$key]) && ! is_nan($sample->value)) {
                    $rows[$key]['p95'] = $sample->value * 1000;
                }
            }
        }

        $rows = array_values($rows);

        usort($rows, static fn (array $a, array $b): int => [$b['cost'] ?? -1.0, $b['calls']] <=> [$a['cost'] ?? -1.0, $a['calls']]);

        $unpriced = $costRead ? array_filter($rows, static fn (array $row): bool => $row['cost'] === null && $row['input'] + $row['output'] > 0) : [];
        $anyPriced = array_filter($rows, static fn (array $row): bool => $row['cost'] !== null) !== [];

        $table = array_map(static function (array $row) use ($currency, $costRead): array {
            // The purpose-built detail page for this model.
            $link = $row['model'] !== ''
                ? Ui::entity('ai-model', $row['model'])
                : Ui::explore('traces', ['gen_ai.operation.name=~chat|embeddings']);
            $errorRate = $row['calls'] > 0 ? $row['failed'] / $row['calls'] : 0.0;

            return [
                'model' => Ui::cell($row['model'] !== '' ? $row['model'] : '(unknown)', [
                    'mono' => true,
                    'link' => $link,
                    ...($row['model'] !== '' ? ['dim' => ['key' => 'gen_ai.request.model', 'value' => $row['model']]] : []),
                    ...($row['operations'] !== [] ? ['sub' => implode(', ', array_keys($row['operations']))] : []),
                ]),
                'provider' => $row['provider'] !== ''
                    ? Ui::cell($row['provider'], ['badge' => $row['provider'], 'tone' => 'info'])
                    : Ui::cell('—'),
                'calls' => Ui::cell(Format::count($row['calls']), ['raw' => $row['calls']]),
                'input' => Ui::cell(Format::count($row['input']), ['raw' => $row['input']]),
                // Embeddings produce no output tokens: a dash, not a zero that reads as a measurement.
                'output' => array_keys($row['operations']) === ['embeddings']
                    ? Ui::cell('—', ['raw' => 0, 'tone' => 'dim'])
                    : Ui::cell(Format::count($row['output']), ['raw' => $row['output']]),
                'cost' => match (true) {
                    $row['cost'] !== null => Ui::cell(Format::money($row['cost'], $currency), ['raw' => $row['cost']]),
                    $costRead && $row['input'] + $row['output'] > 0 => Ui::cell('no price', ['tone' => 'dim']),
                    default => Ui::cell('—'),
                },
                'p95' => $row['p95'] !== null ? Ui::cell(Format::ms($row['p95']), ['raw' => $row['p95']]) : Ui::cell('—'),
                'errors' => $row['calls'] > 0
                    ? Ui::cell(Format::percent($errorRate), ['raw' => $errorRate, 'tone' => $row['failed'] > 0 ? 'danger' : null])
                    : Ui::cell('—'),
                '_link' => $link,
            ];
        }, array_slice($rows, 0, 100));

        $note = match (true) {
            $failure !== null => 'Part of this table is missing: '.lcfirst($failure->getMessage()),
            $costRead && ! $anyPriced && $rows !== [] => self::AI_PRICING_HINT,
            $unpriced !== [] => 'Models marked "no price" used tokens but have no entry in telemetry.instrument.ai_pricing.models, so their cost is unknown, not zero.',
            default => null,
        };

        return Ui::table('Models', [
            Ui::col('model', 'Model'),
            Ui::col('provider', 'Provider'),
            Ui::num('calls', 'Calls'),
            Ui::num('input', 'Input tokens'),
            Ui::num('output', 'Output tokens'),
            Ui::num('cost', 'Cost ('.$currency.')'),
            Ui::num('p95', 'P95'),
            Ui::num('errors', 'Errors'),
        ], $table, array_filter([
            'subtitle' => 'Calls, tokens and cost per provider and model. Click a row for the model',
            'error' => $error,
            'empty' => 'No AI model calls recorded in this period.',
            'note' => $note,
        ], static fn ($v): bool => $v !== null));
    }
}
