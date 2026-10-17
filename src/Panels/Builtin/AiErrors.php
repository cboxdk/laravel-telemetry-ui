<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiUsage;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\Format;

/**
 * Failed model calls by provider and `error_type`, each with its share of
 * that provider's calls — so a rate-limited provider stands out from one
 * that is simply busy.
 */
final class AiErrors extends Panel
{
    use ReadsAiUsage;

    public function data(): array
    {
        $p = $this->promDuration();

        $items = [];

        try {
            $calls = [];

            foreach ($this->metrics()->query($this->metric(self::AI_DURATION.'_count')->increase($p)->sumBy('gen_ai_provider_name')) as $sample) {
                $calls[$sample->labels['gen_ai_provider_name'] ?? ''] = $sample->value;
            }

            foreach ($this->metrics()->query($this->metric(self::AI_DURATION.'_count', 'error_type!=""')->increase($p)->sumBy('gen_ai_provider_name', 'error_type')) as $sample) {
                if ($sample->value <= 0) {
                    continue;
                }

                $provider = $sample->labels['gen_ai_provider_name'] ?? '';
                $type = $sample->labels['error_type'] ?? '';
                $total = $calls[$provider] ?? 0.0;

                $items[] = [
                    'label' => ($provider !== '' ? $provider : 'unknown').' · '.($type !== '' ? $type : 'error'),
                    'value' => $sample->value,
                    'display' => Format::count($sample->value),
                    'sub' => $total > 0 ? Format::percent($sample->value / $total).' of '.Format::count($total).' calls' : null,
                    'tone' => 'danger',
                    'link' => Ui::explore('traces', array_values(array_filter([
                        'gen_ai.operation.name=~chat|embeddings',
                        'status=error',
                        $provider !== '' ? 'gen_ai.provider.name='.$provider : null,
                    ]))),
                ];
            }
        } catch (SourceException $exception) {
            return Ui::bars('AI errors', [], ['error' => $exception->getMessage()]);
        }

        usort($items, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return Ui::bars('AI errors', array_map(
            static fn (array $item): array => array_filter($item, static fn ($v): bool => $v !== null),
            array_slice($items, 0, 20),
        ), [
            'subtitle' => 'Failed model calls by provider and error type',
            'empty' => 'No failed model calls in this period.',
        ]);
    }
}
