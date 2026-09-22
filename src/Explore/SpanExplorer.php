<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Explore;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\AggregatesSpans;
use Cbox\TelemetryUi\Dimensions\Dimensions;
use Cbox\TelemetryUi\Http\Api\Filter;
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

        // Requests are server spans by definition. An unfiltered trace search
        // defaults to server spans too (one row per request-shaped trace, the
        // v1 TraceSearch behaviour) — matching every span would pull whole
        // traces' worth of spans per hit (backends like telemetryd ignore
        // spans-per-spanset limits) and never emit `{}` (all of retention).
        if (($signal === 'requests' || $conditions === []) && ! $hasKind) {
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
    public function rows(RequestScope $scope, string $signal, int $limit = self::DEFAULT_LIMIT, array $extra = [], array $keys = [], bool $perSpan = false): array
    {
        return $this->rowsFrom($this->summaries($scope, $signal, $limit, $extra, $keys), $signal, $keys, $perSpan);
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

        try {
            return $this->connections->traces()->search($this->query($scope, $signal, $extra, $keys), $start, $end, $limit);
        } catch (SourceException $exception) {
            // Some backends (telemetryd) refuse negated regex (`!~`). Rather
            // than fail the whole view, run the query without those filters
            // and apply them read-side to what comes back.
            $negated = array_values(array_filter($scope->where, static fn (Filter $f): bool => $f->op === '!~'));

            if ($negated === [] || ! str_contains(strtolower($exception->detail), 'not supported') && ! str_contains($exception->detail, 'unsupported')) {
                throw $exception;
            }

            $relaxed = $scope->withWhere(array_values(array_filter($scope->where, static fn (Filter $f): bool => $f->op !== '!~')));
            $keys = [...$keys, ...array_map(static fn (Filter $f): string => $f->key, $negated)];

            return array_values(array_filter(
                $this->connections->traces()->search($this->query($relaxed, $signal, $extra, $keys), $start, $end, $limit),
                function (TraceSummary $summary) use ($negated, $signal): bool {
                    $attributes = $this->representative($summary, $signal)->attributes ?? [];

                    foreach ($negated as $filter) {
                        if (! $filter->matches($attributes[$filter->key] ?? '')) {
                            return false;
                        }
                    }

                    return true;
                },
            ));
        }
    }

    /**
     * Span-level occurrences (views, queries, outgoing calls), sampled so the
     * payload stays bounded whatever the backend returns per trace: probe a
     * few traces first; if each carries only a handful of matched spans (Tempo
     * caps spans-per-spanset at 3) widen the sample, if each carries many
     * (telemetryd returns them all) the probe already is the sample.
     *
     * @param  list<TraceCondition>  $extra
     * @param  list<string>  $keys
     * @return list<TraceSummary>
     */
    public function spanSample(RequestScope $scope, array $extra = [], array $keys = [], int $target = 1500): array
    {
        $probe = $this->summaries($scope, 'traces', 10, $extra, $keys);

        if (count($probe) < 10) {
            return $probe;
        }

        $spans = array_sum(array_map(static fn (TraceSummary $s): int => count($s->matchedSpans), $probe));
        $perTrace = max(1, intdiv($spans, count($probe)));
        $limit = min(300, max(10, intdiv($target, $perTrace)));

        return $limit <= 10 ? $probe : $this->summaries($scope, 'traces', $limit, $extra, $keys);
    }

    /**
     * @param  list<TraceSummary>  $summaries
     * @param  list<string>  $keys
     * @return list<Row>
     */
    public function rowsFrom(array $summaries, string $signal, array $keys = [], bool $perSpan = false): array
    {
        $rows = [];

        foreach ($summaries as $summary) {
            if ($perSpan && $summary->matchedSpans !== []) {
                // Span-level entities (a view, a query) occur many times per
                // trace: every matched span is its own occurrence.
                foreach ($summary->matchedSpans as $span) {
                    $rows[] = $this->row($summary, $span, $keys);
                }

                continue;
            }

            $span = $this->representative($summary, $signal);
            $row = $this->row($summary, $span, $keys);

            // A trace row describes the trace: its root operation, service
            // and end-to-end duration — not whichever span happened to match.
            if ($signal === 'traces') {
                $row['name'] = $summary->rootTraceName !== '' ? $summary->rootTraceName : $row['name'];
                $row['durationMs'] = round($summary->durationMs, 3);
                $row['target'] = $row['target'] ?? $row['name'];
            }

            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b): int => $b['startMs'] <=> $a['startMs']);

        return $rows;
    }

    /**
     * Mark rows whose span failed. The span status is an intrinsic that search
     * results don't carry, so a second search with `status = error` names the
     * failing spans — the only way a job/query/view failure (no HTTP status)
     * shows up as a failure at all.
     *
     * @param  list<Row>  $rows
     * @param  list<TraceCondition>  $extra
     * @return list<Row>
     */
    public function markErrors(array $rows, RequestScope $scope, string $signal, array $extra = [], int $limit = self::DEFAULT_LIMIT, bool $wholeTrace = false): array
    {
        if ($rows === []) {
            return $rows;
        }

        try {
            $failed = $this->summaries($scope, $signal, $limit, [...$extra, TraceCondition::token('status', TraceOp::Eq, 'error')]);
        } catch (SourceException) {
            return $rows; // best-effort: the rows stand without it
        }

        $spans = [];
        $traces = [];

        foreach ($failed as $summary) {
            $traces[$summary->traceId] = true;

            foreach ($summary->matchedSpans as $span) {
                $spans[$summary->traceId.':'.$span->spanId] = true;
            }
        }

        foreach ($rows as $i => $row) {
            // A trace row fails when any span in it failed; a span row
            // (one occurrence of a query/view/job) only when that span did.
            $hit = $wholeTrace ? isset($traces[$row['traceId']]) : isset($spans[$row['traceId'].':'.$row['spanId']]);

            if ($hit) {
                $rows[$i]['error'] = true;
                $rows[$i]['attributes']['status'] = 'error';
            }
        }

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

        if ($signal === 'traces') {
            $rows = $this->markErrors($rows, $scope, $signal, [], $limit, wholeTrace: true);
        }

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

        $error = ($status !== null && (int) $status >= 500) || (($span->attributes['status'] ?? '') === 'error');
        // The span status is an intrinsic, not an attribute: surface it as one
        // so the "status" facet and group-by have something to count.
        $attributes['status'] = $error ? 'error' : 'ok';
        // The operation the span ran under (its trace's root) — "who calls
        // this query / renders this view" for span-level entities.
        $attributes['trace.root'] = $summary->rootTraceName;

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
            // The intrinsic span status comes back as a `status` attribute when
            // the query references it; it is not a selectable row attribute,
            // so read it off the raw span rather than the filtered bag.
            'error' => $error,
            'browser' => Span::attributesAreBrowser($span->attributes ?? []),
            'attributes' => $attributes,
        ];
    }
}
