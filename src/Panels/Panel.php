<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Panels;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Contracts\IssuesSource;
use Cbox\TelemetryUi\Contracts\LogsSource;
use Cbox\TelemetryUi\Contracts\MetricsSource;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Panels\Attributes\Param;
use Cbox\TelemetryUi\Panels\Concerns\BuildsCharts;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Results\Sample;
use Cbox\TelemetryUi\Support\Annotation;
use Cbox\TelemetryUi\Support\Annotations;
use Cbox\TelemetryUi\Support\Concerns\ScopesQueries;
use Cbox\TelemetryUi\Support\Period;
use Cbox\TelemetryUi\Support\TimeExpression;
use DateTimeImmutable;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Base class for every dashboard panel — the extension point for the built-in
 * pages and for third-party packages (queue autoscalers, custom spans, anything
 * expressible as PromQL/TraceQL/LogQL).
 *
 * A panel is a plain object: constructed with the request's {@see RequestScope},
 * it builds one JSON payload in {@see data()} (see {@see Ui} for the shapes) and
 * the SPA renders it. There is no view, no component state and no framework
 * lifecycle — the v1 Livewire card minus everything that was not the query.
 *
 * Scope is shared: the time window plus the service/environment selection,
 * bounded by the tenancy lock through {@see ScopesQueries}. Public string
 * properties marked {@see Param} are filled from the query string (the entity
 * key of a detail panel, a panel control).
 *
 * @api This base, its protected scope helpers (metric(), traceQuery(),
 *      logSelector(), range(), scopeMatchers(), the chart/stat builders) and
 *      {@see Ui} are the supported extension surface. Built-in panels are not.
 */
abstract class Panel
{
    use BuildsCharts;
    use ScopesQueries;

    public string $period = '1h';

    public string $from = '';

    public string $to = '';

    public string $service = '';

    public string $environment = '';

    /**
     * The page this panel is rendering on — drill links are suppressed on a
     * panel's own page.
     */
    public string $onPage = 'dashboard';

    /**
     * The page a chart panel drills into from the dashboard (e.g. 'requests');
     * the panel header gains a link there. Null = no drill link.
     */
    protected ?string $drillPage = null;

    final public function __construct(protected readonly RequestScope $scope)
    {
        $this->period = $scope->period;
        $this->from = $scope->from;
        $this->to = $scope->to;
        $this->service = $scope->service;
        $this->environment = $scope->environment;
        $this->onPage = $scope->param('_page', 'dashboard');

        $this->bindParams();
        $this->boot();
    }

    /**
     * The stable id the API and the page registry address this panel by:
     * the kebab-cased class basename (`RoutesTable` → `routes-table`).
     */
    public static function id(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    /**
     * Grid columns this panel spans on a page (1–3). Payloads may override
     * per render with a `span` key.
     */
    public static function span(): int
    {
        return 1;
    }

    /**
     * The panel's payload — an array with a `kind` (see {@see Ui}).
     *
     * @return array<string, mixed>
     */
    abstract public function data(): array;

    /**
     * {@see data()} with {@see statLinks()} applied: what the API serves.
     *
     * @return array<string, mixed>
     */
    final public function serve(): array
    {
        $links = $this->statLinks();

        return $links === [] ? $this->data() : self::linkStats($this->data(), $links);
    }

    /**
     * Where each headline number leads, by stat label — so a tile never dead-
     * ends. Only fills stats that don't already carry a link; recurses into
     * composite parts. Return an empty array for none.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function statLinks(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<string, mixed>>  $links
     * @return array<string, mixed>
     */
    private static function linkStats(array $payload, array $links): array
    {
        if (isset($payload['stats']) && is_array($payload['stats'])) {
            foreach ($payload['stats'] as $i => $stat) {
                if (is_array($stat) && ! isset($stat['link']) && isset($links[$stat['label'] ?? ''])) {
                    $payload['stats'][$i]['link'] = $links[$stat['label']];
                }
            }
        }

        if (isset($payload['parts']) && is_array($payload['parts'])) {
            foreach ($payload['parts'] as $i => $part) {
                if (is_array($part)) {
                    $payload['parts'][$i] = self::linkStats($part, $links);
                }
            }
        }

        return $payload;
    }

    /**
     * Hook for panels that need to normalise their params after binding.
     */
    protected function boot(): void {}

    private function bindParams(): void
    {
        foreach ((new ReflectionClass($this))->getProperties() as $property) {
            $attributes = $property->getAttributes(Param::class);

            if ($attributes === [] || ! $property->isPublic()) {
                continue;
            }

            $name = $attributes[0]->newInstance()->as;

            if (! array_key_exists($name, $this->scope->params)) {
                continue;
            }

            $value = $this->scope->params[$name];
            $type = $property->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'string';

            $property->setValue($this, match ($typeName) {
                'int' => (int) $value,
                'float' => (float) $value,
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => $value,
            });
        }
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
        $from = TimeExpression::parse($this->from);
        $to = TimeExpression::parse($this->to);

        if ($from !== null && $to !== null && $from < $to) {
            return [$from, $to];
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
     * Deploy (and other configured) markers within the active range and scope.
     * Shared across panels via the Annotations cache, so a page costs one lookup.
     *
     * @return list<Annotation>
     */
    protected function annotations(): array
    {
        [$start, $end] = $this->range();

        return app(Annotations::class)->between($start, $end, $this->logSelector());
    }

    /**
     * Annotations shaped for the chart renderer.
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
     * A drill link to another dashboard page carrying extra params. The scope
     * (period/range/service/env) travels with the SPA's own URL state, so it is
     * not repeated here.
     *
     * @param  array<string, string|null>  $extra
     * @return array<string, mixed>
     */
    protected function pageLink(string $page, array $extra = []): array
    {
        return Ui::page($page, array_filter($extra, static fn (?string $v): bool => $v !== null && $v !== ''));
    }

    /**
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

    protected function total(MetricQuery $query): float
    {
        return $this->sumSamples($this->metrics()->query($query));
    }

    /**
     * A counter's total increase over a window, including series born inside it
     * (see {@see MetricQuery::counterIncrease()}). Defaults to the whole period.
     */
    protected function counterIncrease(MetricQuery $query, ?string $window = null): MetricQuery
    {
        return $query->counterIncrease($window ?? $this->promDuration());
    }

    /**
     * Run a range query and reduce each series to a flat list of values, keyed
     * by a caller-built key over its labels — per-row sparkline data.
     *
     * @param  callable(array<string, string>): string  $key
     * @return array<string, list<float>>
     */
    protected function trendByKey(MetricQuery $query, DateTimeImmutable $start, DateTimeImmutable $end, callable $key): array
    {
        $trends = [];

        foreach ($this->metrics()->queryRange($query, $start, $end) as $series) {
            $trends[$key($series->labels)] = array_map(
                static fn ($point): float => $point->value,
                $series->points,
            );
        }

        return $trends;
    }
}
