<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels\Builtin\Detail;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Panels\Concerns\ReadsAiUsage;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Support\Format;

/**
 * One agent's runs over time, failed ones beside them, with what they took
 * and cost — from the same sample as its header, which the entity page's
 * Metrics tab leaves out.
 */
final class AiAgentActivity extends Panel
{
    use ReadsAgentRuns;
    use ReadsAiUsage;
    use ScopesToAiAgent {
        ScopesToAiAgent::scopedAgent insteadof ReadsAiUsage;
    }

    private const BUCKETS = 30;

    public function data(): array
    {
        [$start, $end] = $this->range();

        try {
            $runs = $this->agent === '' ? [] : $this->agentRuns();
        } catch (SourceException $exception) {
            return $this->chartCard('Runs', error: $exception->getMessage());
        }

        $from = $start->getTimestamp();
        $width = max(1, (int) ceil(($end->getTimestamp() - $from) / self::BUCKETS));
        $all = array_fill(0, self::BUCKETS, 0.0);
        $failed = $all;

        foreach ($runs as $run) {
            $i = (int) floor(($run['at'] - $from) / $width);

            if ($i < 0 || $i >= self::BUCKETS) {
                continue;
            }

            $all[$i]++;

            if ($run['failed']) {
                $failed[$i]++;
            }
        }

        $points = static fn (array $counts): array => array_map(
            static fn (int $i, float $n): array => [(float) (($from + $i * $width) * 1000), $n],
            array_keys($counts),
            $counts,
        );

        $failures = (int) array_sum($failed);
        $count = count($runs);
        $priced = array_filter(array_column($runs, 'cost'), static fn (?float $cost): bool => $cost !== null);

        return $this->chartCard(
            title: 'Runs',
            subtitle: 'Agent runs over time, from the most recent runs in this period',
            series: [
                ['name' => 'Runs', 'data' => $points($all), 'color' => '#60a5fa'],
                ['name' => 'Failed', 'data' => $points($failed), 'color' => '#f87171'],
            ],
            stats: [
                $this->stat('Runs', Format::count($count)),
                $this->stat('Failed', Format::count($failures), $failures > 0 ? 'danger' : 'dim'),
                $this->stat('AVG', $count > 0 ? Format::ms(array_sum(array_column($runs, 'durationMs')) / $count) : '—', $count > 0 ? null : 'dim'),
                $this->stat('Cost', $priced !== [] ? Format::money(array_sum($priced), $this->aiCurrency()) : '—', $priced !== [] ? null : 'dim'),
            ],
            type: 'bar',
            note: count($runs) > 0 ? 'Sampled: one Tempo search, so a busy agent shows its most recent runs, not all of them.' : null,
            empty: 'No runs of this agent traced in this period.',
        );
    }
}
