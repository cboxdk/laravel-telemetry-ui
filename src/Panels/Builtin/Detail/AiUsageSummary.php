<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiCalls;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiUsage;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Support\Format;
use Cbox\TelemetryUi\TelemetryUiManager;

/**
 * What one user, team, tenant or IP spent on model calls: calls over time,
 * tokens, cost and errors — exact from the usage records when the app
 * writes them, a recent sample of spans otherwise.
 */
final class AiUsageSummary extends Panel
{
    use ReadsAiCalls;
    use ReadsAiUsage;
    use ScopesToAiDimension {
        ScopesToAiDimension::scopedDimension insteadof ReadsAiUsage;
    }

    private const BUCKETS = 30;

    public static function span(): int
    {
        return 2;
    }

    public function data(): array
    {
        [$start, $end] = $this->range();
        $scope = $this->scopedDimension();

        if ($scope === null) {
            return $this->chartCard('AI usage', empty: 'Open a user, team or tenant to see its AI usage.', span: 2);
        }

        try {
            $read = $this->aiCalls(...$scope);
        } catch (SourceException $exception) {
            return $this->chartCard('AI usage', error: $exception->getMessage(), span: 2);
        }

        $from = $start->getTimestamp() * 1000;
        $width = max(1, (int) ceil(($end->getTimestamp() * 1000 - $from) / self::BUCKETS));
        $all = array_fill(0, self::BUCKETS, 0.0);
        $failed = $all;
        $input = $output = 0.0;
        $cost = null;
        $unpriced = $errors = 0;

        foreach ($read['calls'] as $call) {
            $input += $call['input'];
            $output += $call['output'];
            $errors += $call['error'] !== '' ? 1 : 0;

            if ($call['cost'] !== null) {
                $cost = ($cost ?? 0.0) + $call['cost'];
            } elseif ($call['input'] + $call['output'] > 0) {
                $unpriced++;
            }

            $i = intdiv($call['ts'] - $from, $width);

            if ($i >= 0 && $i < self::BUCKETS) {
                $all[$i]++;
                $failed[$i] += $call['error'] !== '' ? 1 : 0;
            }
        }

        $points = static fn (array $counts): array => array_map(
            static fn (int $i, float $n): array => [(float) ($from + $i * $width), $n],
            array_keys($counts),
            $counts,
        );

        $calls = count($read['calls']);

        return $this->chartCard(
            title: 'AI usage',
            subtitle: 'Model calls, tokens and cost for this '.lcfirst(app(TelemetryUiManager::class)->dimensions()->get($scope[0])->label ?? $scope[0]),
            series: [
                ['name' => 'Calls', 'data' => $points($all), 'color' => '#60a5fa'],
                ['name' => 'Failed', 'data' => $points($failed), 'color' => '#f87171'],
            ],
            stats: [
                $this->stat('Calls', Format::count($calls)),
                $this->stat('Input tokens', Format::count($input)),
                $this->stat('Output tokens', Format::count($output)),
                match (true) {
                    $cost !== null => $this->stat('Cost', Format::money($cost, $this->aiCurrency())),
                    $unpriced > 0 => $this->stat('Cost', 'No price', 'dim'),
                    default => $this->stat('Cost', '—', 'dim'),
                },
                $this->stat('Error rate', Format::percent($calls > 0 ? $errors / $calls : 0.0), $errors > 0 ? 'danger' : 'dim'),
            ],
            type: 'bar',
            span: 2,
            note: $calls > 0 ? self::aiSourceNote($read).($cost !== null && $unpriced > 0 ? ' '.Format::count($unpriced).' calls had no price.' : '') : null,
            empty: 'No model calls for '.$scope[0].' = '.$scope[1].' in this period.',
        );
    }
}
