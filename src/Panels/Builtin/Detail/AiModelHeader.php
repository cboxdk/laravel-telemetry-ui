<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiUsage;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * The header of a model's detail page: the model, its provider and what it
 * is used for, and its headline numbers — calls, tokens, cost (or that it
 * has no price), p95 and error rate.
 */
final class AiModelHeader extends Panel
{
    use ReadsAiUsage;
    use ScopesToAiModel {
        ScopesToAiModel::scopedModel insteadof ReadsAiUsage;
    }

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        $p = $this->promDuration();
        $currency = $this->aiCurrency();

        $error = null;
        $failure = null;
        $providers = [];
        $operations = [];
        $calls = $failed = $input = $output = 0.0;
        $cost = null;
        $costRead = false;
        $p95 = null;

        try {
            foreach ($this->metrics()->query($this->metric(self::AI_DURATION.'_count')->increase($p)->sumBy('gen_ai_provider_name', 'gen_ai_operation_name')) as $sample) {
                $calls += $sample->value;

                if (($provider = $sample->labels['gen_ai_provider_name'] ?? '') !== '') {
                    $providers[$provider] = true;
                }

                if (($operation = $sample->labels['gen_ai_operation_name'] ?? '') !== '') {
                    $operations[$operation] = true;
                }
            }

            $failed = $this->total($this->metric(self::AI_DURATION.'_count', 'error_type!=""')->increase($p)->sumBy());

            foreach ($this->metrics()->query($this->metric(self::AI_TOKENS.'_sum')->increase($p)->sumBy('gen_ai_token_type')) as $sample) {
                match ($sample->labels['gen_ai_token_type'] ?? '') {
                    'input' => $input += $sample->value,
                    'output' => $output += $sample->value,
                    default => null,
                };
            }
        } catch (SourceException $exception) {
            $error = $exception->getMessage();
        }

        if ($error === null) {
            $costs = $this->attempt(fn (): array => $this->metrics()->query($this->counterIncrease($this->metric(self::AI_COST)->sumBy())), $failure);
            $costRead = $costs !== null;
            $cost = $costs !== null && $costs !== [] ? $this->sumSamples($costs) : null;

            $latency = $this->attempt(fn (): array => $this->metrics()->query($this->metric(self::AI_DURATION.'_bucket')->quantile(0.95, $p)), $failure) ?? [];
            $p95 = isset($latency[0]) ? $latency[0]->value * 1000 : null;
        }

        $errorRate = $calls > 0 ? $failed / $calls : 0.0;
        $traces = Ui::explore('traces', ['gen_ai.request.model='.$this->model]);

        return Ui::header(
            $this->model === '' ? '(all models)' : $this->model,
            implode(' · ', array_filter([
                'AI model',
                implode(', ', array_keys($providers)),
                implode(', ', array_keys($operations)),
            ])),
            [
                $this->stat('Calls', Format::count($calls), null, $traces),
                $this->stat('Input tokens', Format::count($input)),
                $this->stat('Output tokens', Format::count($output)),
                match (true) {
                    $cost !== null => $this->stat('Cost', Format::money($cost, $currency)),
                    $costRead && $input + $output > 0 => $this->stat('Cost', 'No price', 'dim'),
                    default => $this->stat('Cost', '—', 'dim'),
                },
                $this->stat('P95', $p95 !== null ? Format::ms($p95) : '—', $p95 !== null ? 'warn' : 'dim'),
                $this->stat('Error rate', Format::percent($errorRate), $failed > 0 ? 'danger' : 'dim', Ui::explore('traces', ['gen_ai.request.model='.$this->model, 'status=error'])),
            ],
            array_filter([
                'back' => Ui::page('ai'),
                'backLabel' => '← All models',
                'error' => $error,
                'note' => match (true) {
                    $failure !== null => 'Part of this header is missing: '.lcfirst($failure->getMessage()),
                    $cost === null && $costRead && $input + $output > 0 => 'This model has no price in telemetry.instrument.ai_pricing.models, so its cost is unknown, not zero.',
                    default => null,
                },
            ], static fn ($v): bool => $v !== null),
        );
    }
}
