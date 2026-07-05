<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\IssuesSource;
use Cbox\TelemetryUi\Contracts\LogsSource;
use Cbox\TelemetryUi\Contracts\MetricsSource;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Queries\Results\Sample;
use Cbox\TelemetryUi\Queries\Results\TimeSeries;
use Cbox\TelemetryUi\Support\Annotation;
use Cbox\TelemetryUi\Support\Annotations;
use Cbox\TelemetryUi\Support\Format;
use Cbox\TelemetryUi\Support\Period;
use Cbox\TelemetryUi\Support\ScopeLock;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
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
 *
 * @api This base and its protected scope helpers (metric(), traceScope(),
 *      logSelector(), range(), scopeMatchers(), escapeLabelValue(), the
 *      chart/stat builders) are the supported extension surface. The built-in
 *      Cards\Builtin\* implementations are not API — subclass this base.
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

    /** True when the card is embedded on a host page (not the built-in dashboard). */
    public bool $embedded = false;

    /**
     * Cards are Livewire components, so any of them can be dropped onto a host
     * page as a widget: `<livewire:telemetry-ui.requests-activity service="cbox-web" period="24h" />`.
     * Passed scope wins over the URL. Embedded or not, a card must still pass
     * the dashboard gate — so an embedded widget can't leak data past
     * `viewTelemetryUi` (the host still controls its own page's auth on top).
     */
    public function mount(
        ?string $service = null,
        ?string $environment = null,
        ?string $period = null,
        ?string $from = null,
        ?string $to = null,
        ?bool $embedded = null,
    ): void {
        $this->embedded = $embedded ?? ($service !== null || $environment !== null || $period !== null || $from !== null || $to !== null);

        // On the dashboard the route already enforces the gate; an embedded
        // widget runs outside those routes, so it must gate itself — a card
        // dropped on a host page can't leak data past viewTelemetryUi.
        if ($this->embedded) {
            abort_unless(Gate::allows('viewTelemetryUi'), 403);
        }

        $this->service = $service ?? $this->service;
        $this->environment = $environment ?? $this->environment;
        $this->period = $period ?? $this->period;
        $this->from = $from ?? $this->from;
        $this->to = $to ?? $this->to;
    }

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

        $services = $this->scopedServices();
        $environments = $this->scopedEnvironments();

        // The marker query takes a single exact value per label, so scope it
        // only when the effective scope is a single service/env (a selection,
        // or a one-value lock). A multi-value lock leaves markers unscoped.
        $matchers = array_filter([
            'service_name' => count($services) === 1 ? $services[0] : '',
            'deployment_environment_name' => count($environments) === 1 ? $environments[0] : '',
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
        $matchers = array_values(array_filter([
            $this->labelMatcher('service_name', $this->scopedServices()),
            $this->labelMatcher('deployment_environment_name', $this->scopedEnvironments()),
        ], static fn (?string $m): bool => $m !== null));

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
        $conditions = array_values(array_filter([
            $this->traceMatcher('resource.service.name', $this->scopedServices()),
            $this->traceMatcher('resource.deployment.environment.name', $this->scopedEnvironments()),
        ], static fn (?string $c): bool => $c !== null));

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
        $matchers = array_values(array_filter([
            $this->labelMatcher('service_name', $this->scopedServices()),
            $this->labelMatcher('deployment_environment_name', $this->scopedEnvironments()),
        ], static fn (?string $m): bool => $m !== null));

        if ($extraMatchers !== '') {
            $matchers[] = $extraMatchers;
        }

        if ($matchers === []) {
            $matchers[] = 'service_name=~".+"';
        }

        return '{'.implode(',', $matchers).'}';
    }

    /**
     * The effective services to scope by: the user's selection when it's
     * allowed by the tenancy lock, otherwise the whole allowed set — so a blank
     * or out-of-bounds `?service=` can never widen past the lock. Empty means
     * unrestricted (no lock, no selection).
     *
     * @return list<string>
     */
    private function scopedServices(): array
    {
        return $this->applyLock($this->service, app(ScopeLock::class)->services());
    }

    /**
     * @return list<string>
     */
    private function scopedEnvironments(): array
    {
        return $this->applyLock($this->environment, app(ScopeLock::class)->environments());
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function applyLock(string $selected, array $allowed): array
    {
        if ($selected !== '' && ($allowed === [] || in_array($selected, $allowed, true))) {
            return [$selected];
        }

        return $allowed;
    }

    /**
     * A PromQL/Loki label matcher for a set of values: exact for one, an RE2
     * alternation for several, nothing for none.
     *
     * @param  list<string>  $values
     */
    private function labelMatcher(string $label, array $values): ?string
    {
        return match (count($values)) {
            0 => null,
            1 => $label.'="'.$this->escapeLabelValue($values[0]).'"',
            default => $label.'=~"'.$this->escapeLabelValue($this->alternation($values)).'"',
        };
    }

    /**
     * A TraceQL scope condition for a set of values.
     *
     * @param  list<string>  $values
     */
    private function traceMatcher(string $key, array $values): ?string
    {
        return match (count($values)) {
            0 => null,
            1 => $key.' = "'.$this->escapeLabelValue($values[0]).'"',
            default => $key.' =~ "'.$this->escapeLabelValue($this->alternation($values)).'"',
        };
    }

    /**
     * A literal RE2 alternation ("a|b") of the values, each regex-escaped so
     * metacharacters match literally.
     *
     * @param  list<string>  $values
     */
    private function alternation(array $values): string
    {
        // Escape only the RE2 metacharacters (not e.g. a hyphen, which
        // preg_quote would), so a plain "web-a|web-b" stays readable.
        $meta = [
            '\\' => '\\\\', '.' => '\\.', '+' => '\\+', '*' => '\\*', '?' => '\\?',
            '(' => '\\(', ')' => '\\)', '[' => '\\[', ']' => '\\]', '{' => '\\{',
            '}' => '\\}', '^' => '\\^', '$' => '\\$', '|' => '\\|',
        ];

        return implode('|', array_map(static fn (string $value): string => strtr($value, $meta), $values));
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
     * A whole metric chart card in one call — the terse path for the common
     * "run a PromQL range query, draw it" card. It queries the range, converts
     * the series, catches backend errors, and renders {@see chartCard()}. Use a
     * grouped query (`sum by (x)(…)`) for multiple lines. Pass $stat to add a
     * headline tile from an instant query ($statQuery, or the same $promql).
     *
     *   public function render(): View
     *   {
     *       return $this->promChart('Queue depth', $this->metric('queue_size'), stat: 'Now');
     *   }
     */
    protected function promChart(
        string $title,
        string $promql,
        ?string $subtitle = null,
        ?string $seriesLabel = null,
        string $type = 'line',
        ?string $unit = null,
        int $span = 1,
        ?string $stat = null,
        ?string $statQuery = null,
    ): View {
        [$start, $end] = $this->range();

        try {
            $series = $this->toChartSeries($this->metrics()->queryRange($promql, $start, $end), $seriesLabel);
            $stats = $stat !== null ? [$this->stat($stat, $this->formatValue($this->total($statQuery ?? $promql), $unit))] : [];
        } catch (SourceException $exception) {
            return $this->chartCard($title, error: $exception->getMessage(), span: $span, subtitle: $subtitle);
        }

        return $this->chartCard($title, series: $series, stats: $stats, type: $type, unit: $unit, span: $span, subtitle: $subtitle);
    }

    /**
     * Format a metric value for a stat tile, picking the formatter from the
     * chart's unit.
     */
    private function formatValue(float $value, ?string $unit): string
    {
        return match ($unit) {
            'bytes' => Format::bytes($value),
            'ms', 'milliseconds' => Format::ms($value),
            'ratio', 'percent' => Format::percent($value),
            default => Format::count($value),
        };
    }

    /**
     * Render the shared "stats + chart" card view (ECharts, annotations, zoom,
     * lazy skeleton, error state) — most metric cards use this instead of
     * shipping their own Blade file. See {@see promChart()} for the terse path.
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
