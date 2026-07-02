<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Contracts\LogsSource;
use Cbox\TelemetryUi\Contracts\MetricsSource;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Cbox\TelemetryUi\Support\Period;
use DateTimeImmutable;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Base class for all dashboard cards — the extension point for both the
 * built-in pages and third-party packages (queue autoscalers, custom spans,
 * anything that can be expressed as PromQL/TraceQL/LogQL).
 */
abstract class Card extends Component
{
    #[Url(as: 'period')]
    public string $period = '1h';

    #[On('telemetry-ui:period-changed')]
    public function updatePeriod(string $period): void
    {
        $this->period = Period::tryFrom($period) !== null ? $period : Period::default()->value;
    }

    protected function period(): Period
    {
        return Period::tryFrom($this->period) ?? Period::default();
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    protected function range(): array
    {
        return $this->period()->range();
    }

    protected function metrics(?string $connection = null): MetricsSource
    {
        return app(ConnectionManager::class)->metrics($connection);
    }

    protected function traces(?string $connection = null): TracesSource
    {
        return app(ConnectionManager::class)->traces($connection);
    }

    protected function logs(?string $connection = null): LogsSource
    {
        return app(ConnectionManager::class)->logs($connection);
    }

    /**
     * Convert TimeSeries results to the shape the <x-telemetry-ui::chart>
     * component feeds to ECharts.
     *
     * @param  list<TimeSeries>  $series
     * @return list<array{name: string, data: list<array{float, float}>}>
     */
    protected function toChartSeries(array $series, ?string $label = null): array
    {
        return array_map(static fn (TimeSeries $timeSeries): array => [
            'name' => $timeSeries->name($label),
            'data' => $timeSeries->toChartData(),
        ], $series);
    }
}
