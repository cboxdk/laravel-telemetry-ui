<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Contracts\LogsSource;
use Cbox\TelemetryUi\Contracts\MetricsSource;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Queries\Results\Sample;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Cbox\TelemetryUi\Support\Period;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Base class for all dashboard cards — the extension point for both the
 * built-in pages and third-party packages (queue autoscalers, custom spans,
 * anything that can be expressed as PromQL/TraceQL/LogQL).
 *
 * Cards share the global scope: the time period plus the service/environment
 * selected in the sidebar switcher, all synced via the query string.
 */
abstract class Card extends Component
{
    #[Url(as: 'period')]
    public string $period = '1h';

    #[Url(as: 'service')]
    public string $service = '';

    #[Url(as: 'env')]
    public string $environment = '';

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

    protected function rateWindow(): string
    {
        return $this->period()->rateWindow();
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
     * A metric reference with the current scope (and any extra matchers)
     * applied: `metric{service_name="checkout",deployment_environment_name="prod"}`.
     */
    protected function metric(string $name, string $extraMatchers = ''): string
    {
        $matchers = [];

        if ($this->service !== '') {
            $matchers[] = 'service_name="'.$this->escapeLabelValue($this->service).'"';
        }

        if ($this->environment !== '') {
            $matchers[] = 'deployment_environment_name="'.$this->escapeLabelValue($this->environment).'"';
        }

        if ($extraMatchers !== '') {
            $matchers[] = $extraMatchers;
        }

        return $matchers === [] ? $name : $name.'{'.implode(',', $matchers).'}';
    }

    /**
     * TraceQL conditions for the current scope, AND-joined with any extra
     * conditions: `resource.service.name = "checkout" && <extra>`.
     */
    protected function traceScope(string $extraConditions = ''): string
    {
        $conditions = [];

        if ($this->service !== '') {
            $conditions[] = 'resource.service.name = "'.$this->escapeLabelValue($this->service).'"';
        }

        if ($this->environment !== '') {
            $conditions[] = 'resource.deployment.environment.name = "'.$this->escapeLabelValue($this->environment).'"';
        }

        if ($extraConditions !== '') {
            $conditions[] = $extraConditions;
        }

        return implode(' && ', $conditions);
    }

    /**
     * A Loki stream selector for the current scope. Loki requires at least
     * one non-empty matcher, so an unscoped selector matches any service.
     */
    protected function logSelector(string $extraMatchers = ''): string
    {
        $matchers = [];

        if ($this->service !== '') {
            $matchers[] = 'service_name="'.$this->escapeLabelValue($this->service).'"';
        }

        if ($this->environment !== '') {
            $matchers[] = 'deployment_environment_name="'.$this->escapeLabelValue($this->environment).'"';
        }

        if ($extraMatchers !== '') {
            $matchers[] = $extraMatchers;
        }

        if ($matchers === []) {
            $matchers[] = 'service_name=~".+"';
        }

        return '{'.implode(',', $matchers).'}';
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

    /**
     * Sum of all samples of an instant query result — the "total over the
     * period" building block for stat headers.
     *
     * @param  list<Sample>  $samples
     */
    protected function sumSamples(array $samples): float
    {
        $total = 0.0;

        foreach ($samples as $sample) {
            $total += $sample->value;
        }

        return $total;
    }

    /**
     * Evaluate an instant query and sum all its samples.
     */
    protected function total(string $promql): float
    {
        return $this->sumSamples($this->metrics()->query($promql));
    }

    /**
     * A stats-row item for the generic chart card / <x-telemetry-ui::stats>.
     *
     * @return array{label: string, value: string, tone: string|null}
     */
    protected function stat(string $label, string $value, ?string $tone = null): array
    {
        return ['label' => $label, 'value' => $value, 'tone' => $tone];
    }

    /**
     * Render the shared "stats + chart" card view, which most metric cards
     * use instead of shipping their own Blade file.
     *
     * @param  list<array{name: string, data: list<array{float, float}>, color?: string}>  $series
     * @param  list<array{label: string, value: string, tone: string|null}>  $stats
     */
    protected function chartCard(
        string $title,
        array $series = [],
        array $stats = [],
        string $type = 'line',
        ?string $unit = null,
        ?string $error = null,
        int $span = 1,
        ?string $note = null,
        int $height = 200,
    ): View {
        /** @var view-string $view */
        $view = 'telemetry-ui::cards.chart';

        return view($view, [
            'title' => $title,
            'series' => $series,
            'stats' => $stats,
            'type' => $type,
            'unit' => $unit,
            'error' => $error,
            'span' => $span,
            'note' => $note,
            'height' => $height,
        ]);
    }

    private function escapeLabelValue(string $value): string
    {
        return addcslashes($value, '"\\');
    }
}
