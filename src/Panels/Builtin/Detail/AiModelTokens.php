<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiUsage;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Support\Format;

/**
 * One model's tokens per minute, input and output, with what they cost —
 * or that the model has no price.
 */
final class AiModelTokens extends Panel
{
    use ReadsAiUsage;
    use ScopesToAiModel {
        ScopesToAiModel::scopedModel insteadof ReadsAiUsage;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();
        $currency = $this->aiCurrency();
        $tokens = $this->metric(self::AI_TOKENS.'_sum');

        $totals = ['input' => 0.0, 'output' => 0.0];

        try {
            $range = $this->metrics()->queryRange($tokens->rate($this->rateWindow())->sumBy('gen_ai_token_type')->times(60), $start, $end);
            $costs = $this->metrics()->query($this->counterIncrease($this->metric(self::AI_COST)->sumBy()));

            foreach ($this->metrics()->query($tokens->increase($this->promDuration())->sumBy('gen_ai_token_type')) as $sample) {
                $type = $sample->labels['gen_ai_token_type'] ?? '';

                if (isset($totals[$type])) {
                    $totals[$type] += $sample->value;
                }
            }
        } catch (SourceException $exception) {
            return $this->chartCard('Tokens', error: $exception->getMessage());
        }

        $colors = ['input' => '#60a5fa', 'output' => '#a78bfa'];
        $series = [];

        foreach ($range as $timeSeries) {
            $type = $timeSeries->labels['gen_ai_token_type'] ?? '';
            $series[] = ['name' => ucfirst($type !== '' ? $type : 'tokens'), 'data' => $timeSeries->toChartData(), 'color' => $colors[$type] ?? '#a1a1aa'];
        }

        $priced = $costs !== [];

        return $this->chartCard(
            title: 'Tokens',
            subtitle: 'Tokens per minute, input and output',
            series: $series,
            stats: [
                $this->stat('Input', Format::count($totals['input'])),
                $this->stat('Output', Format::count($totals['output'])),
                $priced
                    ? $this->stat('Cost', Format::money($this->sumSamples($costs), $currency))
                    : $this->stat('Cost', 'No price', 'dim'),
            ],
            type: 'area',
            unit: 'tokens/min',
            note: $priced || $totals['input'] + $totals['output'] <= 0 ? null : 'No price configured for this model in telemetry.instrument.ai_pricing.models.',
            empty: 'No tokens recorded for this model in this period.',
        );
    }
}
