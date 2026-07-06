<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Builtin;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * Cluster capacity: spawned workers against cluster-wide demand and
 * capacity, with manager count, utilization and the leader's host
 * recommendation. From the queue_autoscale.cluster.* observable gauges.
 */
final class AutoscaleCluster extends Card
{
    protected ?string $drillPage = 'autoscale';

    private const SERIES = [
        'queue_autoscale_cluster_workers' => ['Workers', '#34d399'],
        'queue_autoscale_cluster_required_workers' => ['Required', '#fbbf24'],
        'queue_autoscale_cluster_capacity' => ['Capacity', '#71717a'],
    ];

    public function render(): View
    {
        [$start, $end] = $this->range();

        $series = [];

        try {
            foreach (self::SERIES as $metric => [$label, $color]) {
                $range = $this->metrics()->queryRange('max('.$this->metric($metric).')', $start, $end);

                if (isset($range[0])) {
                    $series[] = ['name' => $label, 'data' => $range[0]->toChartData(), 'color' => $color];
                }
            }

            $managers = $this->total('max('.$this->metric('queue_autoscale_cluster_managers').')');
            $utilization = $this->total('max('.$this->metric('queue_autoscale_cluster_utilization_percent').')');
            $hosts = $this->total('max('.$this->metric('queue_autoscale_cluster_recommended_hosts').')');
        } catch (SourceException $exception) {
            return $this->chartCard('Cluster', error: $exception->getMessage());
        }

        return $this->chartCard(
            title: 'Cluster',
            subtitle: 'Spawned workers vs cluster-wide demand and capacity',
            series: $series,
            stats: [
                $this->stat('Managers', Format::count($managers), $managers > 0 ? null : 'dim'),
                $this->stat('Utilization', Format::percent($utilization / 100), $utilization >= 90 ? 'warn' : null),
                $this->stat('Hosts advised', Format::count($hosts), null),
            ],
            unit: 'workers',
        );
    }
}
