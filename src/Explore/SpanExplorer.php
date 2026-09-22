<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Explore;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Contracts\AggregatesSpans;
use Cbox\TelemetryUi\Dimensions\Dimensions;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Queries\Ir\SpanAggregation;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Queries\Results\MatchedSpan;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanBucket;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;

/**
 * Explore over spans — the `requests` (server spans) and `traces` signals.
 *
 * One TraceQL search (scope + URL filters, through the IR) returns a bounded,
 * newest-first sample of matching spans with the attributes we need selected.
 * Stats, group-by, the heatmap and facets fold that sample read-side and say
 * so (`sample.exact = false`). When the traces backend can aggregate
 * ({@see AggregatesSpans}: the ClickHouse store, telemetryd), group-by and
 * facets are computed over EVERY matching span instead.
 *
 * @phpstan-type Row array{traceId: string, spanId: string, startMs: int, time: string, durationMs: float, name: string, service: string, method: string|null, route: string|null, path: string|null, target: string|null, status: string|null, error: bool, browser: bool, attributes: array<string, string>}
 */
final class SpanExplorer
{
    public const DEFAULT_LIMIT = 500;

    public const MAX_LIMIT = 2000;

    /** Attributes every row carries, whatever the dimension registry says. */
    private const CORE_KEYS = [
        'http.request.method', 'http.route', 'url.path', 'http.response.status_code',
        'user.id', 'client.address', 'host.name', 'http.url', 'browser',
    ];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Dimensions $dimensions,
    ) {}

    /**
     * The TraceQL query for a signal within a scope: scope + filters (+ the
     * request-shaped default), selecting the attributes rows and facets need.
     *
     * @param  list<TraceCondition>  $extra
     * @param  list<string>  $keys  extra attribute keys to select (facets, group-by)
     */
    public function query(RequestScope $scope, string $signal, array $extra = [], array $keys = []): TraceQuery
    {
        $conditions = [...TraceFilters::conditions($scope->where, $this->dimensions), ...$extra];

        $hasKind = array_filter($conditions, static fn (TraceCondition $c): bool => $c->field === 'kind') !== [];

        if (($signal === 'requests' && ! $hasKind) || ($conditions === [] && $scope->scopeTraceConditions() === [])) {
            // Never emit `{}` (every span in retention): default to server spans.
            $conditions[] = TraceCondition::token('kind', TraceOp::Eq, 'server');
        }

        $q = trim($scope->param('q'));

        if ($q !== '') {
            $conditions[] = TraceCondition::re('name', '.*'.preg_quote($q, '/').'.*');
        }

        $select = [];

        foreach (array_unique([...self::CORE_KEYS, ...$this->dimensionKeys(), ...$keys]) as $key) {
            $dimension = $this->dimensions->resolve($key);

            if ($dimension->scope !== 'intrinsic') {
                $select[] = $dimension->traceField();
            }
        }

        return $scope->scopedTraceQuery(...$conditions)->select(...array_values(array_unique($select)));
    }

    /**
     * Search and flatten to request-shaped rows, newest first.
     *
     * @param  list<TraceCondition>  $extra
     * @param  list<string>  $keys
     * @return list<Row>
     */
    public function rows(RequestScope $scope, string $signal, int $limit = self::DEFAULT_LIMIT, array $extra = [], array $keys = []): array
    {
        return $this->rowsFrom($this->summaries($scope, $signal, $limit, $extra, $keys), $signal, $keys);
    }

    /**
     * The raw search hits (for callers that need full attribute bags).
     *
     * @param  list<TraceCondition>  $extra
     * @param  list<string>  $keys
     * @return list<TraceSummary>
     */
    public function summaries(RequestScope $scope, string $signal, int $limit = self::DEFAULT_LIMIT, array $extra = [], array $keys = []): array
    {
        [$start, $end] = $scope->range();
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        return $this->connections->traces()->search($this->query($scope, $signal, $extra, $keys), $start, $end, $limit);
    }

    /**
     * @param  list<TraceSummary>  $summaries
     * @param  list<string>  $keys
     * @return list<Row>
     */
    public function rowsFrom(array $summaries, string $signal, array $keys = []): array
    {
        $rows = [];

        foreach ($summaries as $summary) {
            $span = $this->representative($summary, $signal);
            $rows[] = $this->row($summary, $span, $keys);
        }

        usort($rows, static fn (array $a, array $b): int => $b['startMs'] <=> $a['startMs']);

        return $rows;
    }

    /**
     * The full Explore payload for a span signal.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function explore(RequestScope $scope, string $signal, int $limit, ?string $groupBy, array $keys = []): array
    {
        $groupBy = $groupBy !== null && $groupBy !== '' ? $groupBy : null;
        $keys = $groupBy !== null ? [...$keys, $groupBy] : $keys;

        $rows = $this->rows($scope, $signal, $limit, [], $keys);
        [$start, $end] = $scope->range();
        $startMs = $start->getTimestamp() * 1000;
        $endMs = $end->getTimestamp() * 1000;

        $groups = null;
        $exact = false;

        if ($groupBy !== null) {
            $exactGroups = $this->exactGroups($scope, $signal, $groupBy);
            $exact = $exactGroups !== null;
            $groups = $exactGroups ?? Stats::groupBy($rows, $groupBy);
        }

        return [
            'signal' => $signal,
            'rows' => $rows,
            'stats' => Stats::red($rows, $scope->rangeSeconds()),
            'series' => Stats::series($rows, $startMs, $endMs),
            'heatmap' => Stats::heatmap($rows, $startMs, $endMs),
            'groupBy' => $groupBy,
            'groups' => $groups,
            'sample' => [
                'size' => count($rows),
                'limit' => $limit,
                'truncated' => count($rows) >= $limit,
                'exact' => false,
                'groupsExact' => $exact,
            ],
            'range' => ['start' => $startMs, 'end' => $endMs],
        ];
    }

    /**
     * Facets: top values per key. Exact via the backend's span aggregation
     * when available, else counted over the search sample.
     *
     * @param  list<string>  $keys
     * @return array{facets: list<array{key: string, label: string, group: string|null, custom: bool, values: list<array{value: string, count: int}>}>, exact: bool, sample: int}
     */
    public function facets(RequestScope $scope, string $signal, array $keys, int $limit = self::DEFAULT_LIMIT): array
    {
        $keys = $keys !== [] ? array_values(array_unique($keys)) : $this->defaultFacetKeys($signal);

        $exact = $this->connections->traces() instanceof AggregatesSpans;
        $rows = $exact ? [] : $this->rows($scope, $signal, $limit, [], $keys);
        $bags = array_map(static fn (array $row): array => $row['attributes'], $rows);

        $facets = [];

        foreach ($keys as $key) {
            $dimension = $this->dimensions->resolve($key);
            $values = $exact ? ($this->exactTopValues($scope, $signal, $key) ?? []) : Stats::topValues($bags, $key);

            $facets[] = [
                'key' => $key,
                'label' => $dimension->label,
                'group' => $dimension->group,
                'custom' => ! $dimension->builtin && $this->dimensions->get($key) !== null,
                'values' => $values,
            ];
        }

        return ['facets' => $facets, 'exact' => $exact, 'sample' => count($rows)];
    }

    /**
     * @return list<string>
     */
    public function defaultFacetKeys(string $signal): array
    {
        $keys = [];

        foreach ($this->dimensions->all() as $dimension) {
            if (! $dimension->builtin || in_array($signal, $dimension->signals, true)) {
                $keys[] = $dimension->key;
            }
        }

        return $keys;
    }

    /**
     * Declared dimension keys (so rows carry them for chips and facets).
     *
     * @return list<string>
     */
    private function dimensionKeys(): array
    {
        return array_values(array_filter(
            array_map(static fn ($d): string => $d->key, $this->dimensions->all()),
            fn (string $key): bool => $this->dimensions->resolve($key)->scope !== 'intrinsic',
        ));
    }

    /**
     * @return list<array{value: string, count: int, errors: int, errorRate: float, avg: float, p95: float|null, share: float}>|null
     */
    private function exactGroups(RequestScope $scope, string $signal, string $key): ?array
    {
        $buckets = $this->aggregate($scope, $signal, $key, 50);

        if ($buckets === null) {
            return null;
        }

        $total = max(1, array_sum(array_map(static fn (SpanBucket $b): int => $b->count, $buckets)));

        return array_map(static fn (SpanBucket $b): array => [
            'value' => $b->key === '' ? '(none)' : $b->key,
            'count' => $b->count,
            'errors' => 0,
            'errorRate' => 0.0,
            'avg' => $b->avgMs,
            'p95' => $b->p95Ms,
            'share' => $b->count / $total,
        ], $buckets);
    }

    /**
     * @return list<array{value: string, count: int}>|null
     */
    private function exactTopValues(RequestScope $scope, string $signal, string $key): ?array
    {
        $buckets = $this->aggregate($scope, $signal, $key, 8);

        return $buckets === null ? null : array_values(array_map(
            static fn (SpanBucket $b): array => ['value' => $b->key, 'count' => $b->count],
            array_filter($buckets, static fn (SpanBucket $b): bool => $b->key !== ''),
        ));
    }

    /**
     * @return list<SpanBucket>|null
     */
    private function aggregate(RequestScope $scope, string $signal, string $key, int $limit): ?array
    {
        $source = $this->connections->traces();

        if (! $source instanceof AggregatesSpans) {
            return null;
        }

        $dimension = $this->dimensions->resolve($key);

        if ($dimension->scope === 'intrinsic') {
            return null;
        }

        [$start, $end] = $scope->range();

        $query = $this->query($scope, $signal);

        return $source->aggregateSpans(
            new SpanAggregation(new TraceQuery($query->conditions), $dimension->traceField(), limit: $limit),
            $start,
            $end,
        );
    }

    /**
     * The matched span a row describes — the one carrying request context for
     * `requests`, else the first matched span.
     */
    public function representative(TraceSummary $summary, string $signal): ?MatchedSpan
    {
        foreach ($summary->matchedSpans as $span) {
            if (isset($span->attributes['http.request.method']) || isset($span->attributes['http.route'])) {
                return $span;
            }
        }

        return $summary->matchedSpans[0] ?? null;
    }

    /**
     * @param  list<string>  $keys
     * @return Row
     */
    private function row(TraceSummary $summary, ?MatchedSpan $span, array $keys): array
    {
        $attributes = [];
        $wanted = array_flip([...self::CORE_KEYS, ...$this->dimensionKeys(), ...$keys]);

        foreach ($span->attributes ?? [] as $key => $value) {
            if (isset($wanted[$key]) && (is_scalar($value) || $value === null)) {
                $attributes[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        $status = $attributes['http.response.status_code'] ?? null;
        $startMs = $span !== null ? intdiv($span->startNano, 1_000_000) : (int) $summary->startedAt->format('Uv');
        $durationMs = $span !== null ? $span->durationMs : $summary->durationMs;
        $route = $attributes['http.route'] ?? null;
        $path = $attributes['url.path'] ?? ($attributes['http.url'] ?? null);

        return [
            'traceId' => $summary->traceId,
            'spanId' => $span->spanId ?? '',
            'startMs' => $startMs,
            'time' => gmdate('Y-m-d\TH:i:s', intdiv($startMs, 1000)).sprintf('.%03dZ', $startMs % 1000),
            'durationMs' => round($durationMs, 3),
            'name' => $span !== null && $span->name !== '' ? $span->name : ($summary->rootTraceName !== '' ? $summary->rootTraceName : '(unnamed)'),
            'service' => $summary->rootServiceName,
            'method' => $attributes['http.request.method'] ?? null,
            'route' => $route,
            'path' => $path,
            'target' => $route ?? $path,
            'status' => $status,
            'error' => ($status !== null && (int) $status >= 500) || (($attributes['status'] ?? '') === 'error'),
            'browser' => Span::attributesAreBrowser($span->attributes ?? []),
            'attributes' => $attributes,
        ];
    }
}
