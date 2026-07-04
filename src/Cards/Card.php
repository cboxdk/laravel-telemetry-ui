<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Contracts\IssuesSource;
use Cbox\TelemetryUi\Contracts\LogsSource;
use Cbox\TelemetryUi\Contracts\MetricsSource;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Queries\Results\Sample;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Cbox\TelemetryUi\Support\Annotation;
use Cbox\TelemetryUi\Support\Annotations;
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

    /**
     * Custom absolute range (unix seconds); overrides the preset period
     * when both ends are present. Set by the range picker and chart zoom.
     */
    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    #[Url(as: 'service')]
    public string $service = '';

    #[Url(as: 'env')]
    public string $environment = '';

    #[On('telemetry-ui:period-changed')]
    public function updatePeriod(string $period): void
    {
        $this->period = Period::tryFrom($period) !== null ? $period : Period::default()->value;
        $this->from = '';
        $this->to = '';
    }

    /**
     * Skeleton rendered instantly in the page shell while the card streams in
     * its own request (lazy 'on-load'), so one slow backend query never blocks
     * the whole page. Cards with heavy layout may override to match.
     */
    public function placeholder(): View
    {
        /** @var view-string $view */
        $view = 'telemetry-ui::cards.placeholder';

        return view($view);
    }

    /**
     * Auto-refresh tick from the header control; re-renders the card.
     */
    #[On('telemetry-ui:refresh')]
    public function pollRefresh(): void {}

    protected function period(): Period
    {
        return Period::tryFrom($this->period) ?? Period::default();
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    protected function range(): array
    {
        if (ctype_digit($this->from) && ctype_digit($this->to)) {
            $from = new DateTimeImmutable('@'.$this->from);
            $to = new DateTimeImmutable('@'.$this->to);

            if ($from < $to) {
                return [$from, $to];
            }
        }

        return $this->period()->range();
    }

    protected function rangeSeconds(): int
    {
        [$start, $end] = $this->range();

        return max(1, $end->getTimestamp() - $start->getTimestamp());
    }

    /**
     * The whole active range as a PromQL duration, for period totals.
     */
    protected function promDuration(): string
    {
        return $this->rangeSeconds().'s';
    }

    protected function rateWindow(): string
    {
        return Period::windowFor($this->rangeSeconds());
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

    protected function issues(?string $connection = null): IssuesSource
    {
        return app(ConnectionManager::class)->issues($connection);
    }

    /**
     * Deploy (and other configured) markers within the active range and
     * scope, for drawing as chart annotation lines. Shared across cards via
     * the Annotations cache, so a page of charts costs one lookup.
     *
     * @return list<Annotation>
     */
    protected function annotations(): array
    {
        [$start, $end] = $this->range();

        $matchers = array_filter([
            'service_name' => $this->service,
            'deployment_environment_name' => $this->environment,
        ]);

        return app(Annotations::class)->between($start, $end, $matchers);
    }

    /**
     * Annotations shaped for the chart component's ECharts markLine.
     *
     * @return list<array<string, mixed>>
     */
    protected function annotationMarks(): array
    {
        return array_map(
            static fn (Annotation $annotation): array => $annotation->toMarkLine(),
            $this->annotations(),
        );
    }

    /**
     * Extra PromQL label matchers applied to every {@see metric()} on this
     * card — an entity-detail card (a single route, host, …) overrides this to
     * scope the whole card to that entity. Empty by default.
     */
    protected function scopeMatchers(): string
    {
        return '';
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

        if (($scope = $this->scopeMatchers()) !== '') {
            $matchers[] = $scope;
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
     * Run a range query and reduce each series to a flat list of values,
     * keyed by a caller-built key over its labels — the per-row trend data
     * behind table sparklines.
     *
     * @param  callable(array<string, string>): string  $key
     * @return array<string, list<float>>
     */
    protected function trendByKey(string $promql, DateTimeImmutable $start, DateTimeImmutable $end, callable $key): array
    {
        $trends = [];

        foreach ($this->metrics()->queryRange($promql, $start, $end) as $series) {
            $trends[$key($series->labels)] = array_map(
                static fn ($point): float => $point->value,
                $series->points,
            );
        }

        return $trends;
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
        bool $annotate = true,
        ?string $subtitle = null,
    ): View {
        [$start, $end] = $this->range();

        // A series of all-zero points renders as a flat, broken-looking line;
        // treat "present but no activity" as empty so the card shows a clean
        // state instead. Genuine data with any non-zero point still charts.
        if ($series !== [] && ! $this->seriesHasSignal($series)) {
            $series = [];
        }

        /** @var view-string $view */
        $view = 'telemetry-ui::cards.chart';

        return view($view, [
            'title' => $title,
            'subtitle' => $subtitle,
            'series' => $series,
            'stats' => $stats,
            'type' => $type,
            'unit' => $unit,
            'error' => $error,
            'span' => $span,
            'note' => $note,
            'height' => $height,
            'annotations' => $annotate && $series !== [] ? $this->annotationMarks() : [],
            'min' => $start->getTimestamp() * 1000,
            'max' => $end->getTimestamp() * 1000,
        ]);
    }

    /**
     * Whether any series carries a non-zero data point.
     *
     * @param  list<array{name: string, data: list<array{float, float}>, color?: string}>  $series
     */
    private function seriesHasSignal(array $series): bool
    {
        foreach ($series as $entry) {
            foreach ($entry['data'] as $point) {
                if (($point[1] ?? 0.0) != 0.0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function escapeLabelValue(string $value): string
    {
        return addcslashes($value, '"\\');
    }
}
