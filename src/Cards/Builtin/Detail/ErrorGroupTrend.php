<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin\Detail;

use Cbox\TelemetryUi\Analysis\ErrorGroupReport;
use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Illuminate\Contracts\View\View;

/**
 * The issue's event trend across the page period — with the standard chart
 * annotations on top, so deploys/purges line up against the error volume
 * exactly like Sentry's release markers.
 */
final class ErrorGroupTrend extends Card
{
    use ScopesToGroup;

    private const BUCKETS = 48;

    public function render(): View
    {
        [$start, $end] = $this->range();

        try {
            $occurrences = $this->groupReport()['occurrences'];
        } catch (SourceException $exception) {
            return $this->chartCard('Events', error: $exception->getMessage(), span: 1);
        }

        $fromNano = $start->getTimestamp() * 1_000_000_000;
        $toNano = $end->getTimestamp() * 1_000_000_000;
        $spanNano = max(1, $toNano - $fromNano);

        $buckets = array_fill(0, self::BUCKETS, 0);

        foreach ($occurrences as $occurrence) {
            $nano = (int) $occurrence['nano'];

            if ($nano < $fromNano || $nano > $toNano) {
                continue;
            }

            $buckets[min(self::BUCKETS - 1, (int) (($nano - $fromNano) / $spanNano * self::BUCKETS))]++;
        }

        $points = [];

        foreach ($buckets as $i => $count) {
            $points[] = [($fromNano + (int) ($spanNano * ($i + 0.5) / self::BUCKETS)) / 1_000_000, (float) $count];
        }

        return $this->chartCard(
            title: 'Events',
            subtitle: 'Occurrences of this group per interval — deploy/change markers drawn on top',
            series: [['name' => 'events', 'data' => $points, 'color' => '#f87171']],
            type: 'bar',
            unit: 'events',
            span: 1,
            note: 'Bounded sample (last '.ErrorGroupReport::LOOKBACK_DAYS.' days, max '.ErrorGroupReport::SEARCH_LIMIT.' occurrences).',
        );
    }
}
