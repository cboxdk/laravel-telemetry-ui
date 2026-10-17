<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiUsage;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * What the app spends on model calls: cost per model over time when prices
 * are configured, tokens per model otherwise — with the period's cost,
 * tokens, calls and error rate as headline tiles.
 */
final class AiOverview extends Panel
{
    use ReadsAiUsage;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();
        $p = $this->promDuration();

        $calls = $this->metric(self::AI_DURATION.'_count');
        $failed = $this->metric(self::AI_DURATION.'_count', 'error_type!=""');
        $tokens = $this->metric(self::AI_TOKENS.'_sum');
        $cost = $this->metric(self::AI_COST);
        $currency = $this->aiCurrency();

        try {
            // A counter that only exists for priced models: no series at all
            // means nothing is priced, which is a setup hint, not a zero.
            $costSamples = $this->metrics()->query($this->counterIncrease($cost->sumBy()));
            $priced = $costSamples !== [];
            $totalCost = $this->sumSamples($costSamples);

            $byType = ['input' => 0.0, 'output' => 0.0];

            foreach ($this->metrics()->query($tokens->increase($p)->sumBy('gen_ai_token_type')) as $sample) {
                $type = $sample->labels['gen_ai_token_type'] ?? '';

                if (isset($byType[$type])) {
                    $byType[$type] += $sample->value;
                }
            }

            $totalCalls = $this->total($calls->increase($p)->sumBy());
            $totalFailed = $this->total($failed->increase($p)->sumBy());

            $range = $priced
                ? $this->metrics()->queryRange($cost->rate($this->rateWindow())->sumBy('gen_ai_request_model')->times(3600), $start, $end)
                : $this->metrics()->queryRange($tokens->rate($this->rateWindow())->sumBy('gen_ai_request_model')->times(60), $start, $end);
        } catch (SourceException $exception) {
            return $this->chartCard('AI usage', error: $exception->getMessage(), span: 2);
        }

        $errorRate = $totalCalls > 0 ? $totalFailed / $totalCalls : 0.0;

        return $this->chartCard(
            title: 'AI usage',
            subtitle: $priced
                ? 'What model calls cost, per model per hour ('.$currency.')'
                : 'Tokens used by model calls, per model per minute',
            series: $this->toChartSeries($range, 'gen_ai_request_model'),
            stats: [
                $priced
                    ? $this->stat('Cost', Format::money($totalCost, $currency))
                    : $this->stat('Cost', 'No prices', 'dim'),
                $this->stat('Input tokens', Format::count($byType['input'])),
                $this->stat('Output tokens', Format::count($byType['output'])),
                $this->stat('Model calls', Format::count($totalCalls), null, Ui::explore('traces', ['gen_ai.operation.name=~chat|embeddings'])),
                $this->stat('Error rate', Format::percent($errorRate), $totalFailed > 0 ? 'danger' : 'dim', Ui::explore('traces', ['gen_ai.operation.name=~chat|embeddings', 'status=error'])),
            ],
            type: 'area',
            unit: $priced ? $currency.'/h' : 'tokens/min',
            span: 2,
            note: $priced ? null : self::AI_PRICING_HINT,
            empty: 'No AI model calls recorded in this period.',
        );
    }
}
