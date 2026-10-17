<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiUsage;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Support\Format;

/**
 * How long chat calls take per provider — p95 over time (p50 and p95 for a
 * single model), from the
 * GenAI operation-duration histogram. Embeddings are left out: they are a
 * different order of magnitude and would flatten the chat lines. Scoped to
 * one model (its detail page), it charts that model whatever it does.
 */
class AiLatency extends Panel
{
    use ReadsAiUsage;

    public function data(): array
    {
        [$start, $end] = $this->range();
        $p = $this->promDuration();

        $scoped = $this->scopedModel() !== '';
        $title = $scoped ? 'Latency' : 'Chat latency';
        $bucket = $this->metric(self::AI_DURATION.'_bucket', $scoped ? '' : 'gen_ai_operation_name="chat"');

        try {
            $series = [];

            // Across providers, p50 and p95 each doubled the lines and wrapped
            // the legend over the axis; the tiles carry the overall p50.
            $quantiles = $scoped ? [[0.5, 'p50'], [0.95, 'p95']] : [[0.95, 'p95']];

            foreach ($quantiles as [$quantile, $label]) {
                foreach ($this->metrics()->queryRange($bucket->quantile($quantile, $this->rateWindow(), 'gen_ai_provider_name')->times(1000), $start, $end) as $timeSeries) {
                    $provider = $timeSeries->labels['gen_ai_provider_name'] ?? '';

                    $series[] = [
                        'name' => ($provider !== '' ? $provider : 'unknown').($scoped ? ' '.$label : ''),
                        'data' => $timeSeries->toChartData(),
                    ];
                }
            }

            $p50 = $this->metrics()->query($bucket->quantile(0.5, $p));
            $p95 = $this->metrics()->query($bucket->quantile(0.95, $p));
        } catch (SourceException $exception) {
            return $this->chartCard($title, error: $exception->getMessage());
        }

        // The driver drops NaN (no observations), so an empty result is "—".
        $headline = static fn (array $samples): string => isset($samples[0]) ? Format::ms($samples[0]->value * 1000) : '—';

        return $this->chartCard(
            title: $title,
            subtitle: $scoped ? 'p50 and p95 of calls to this model, per provider' : 'p95 of chat calls per provider; overall p50 and p95 above',
            series: $series,
            stats: [
                $this->stat('p50', $headline($p50)),
                $this->stat('p95', $headline($p95), 'warn'),
            ],
            unit: 'ms',
            empty: $scoped ? 'No calls to this model recorded in this period.' : 'No chat calls recorded in this period.',
        );
    }
}
