<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Api;

use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Support\Concerns\ScopesQueries;
use Cbox\TelemetryUi\Support\Period;
use Cbox\TelemetryUi\Support\ScopeLock;
use Cbox\TelemetryUi\Support\TimeExpression;
use Cbox\TelemetryUi\Support\ViewState;
use DateTimeImmutable;
use Illuminate\Http\Request;

/**
 * The per-request scope every API endpoint queries within: the time window,
 * the service/environment selection and the active dimension filters — read
 * from query parameters, never from server-side session state.
 *
 * This is where the v1 Livewire component state went. The fail-closed tenancy
 * semantics are unchanged: every query builder here goes through
 * {@see ScopesQueries}, so a blank or out-of-bounds `?service=` can never widen
 * past the viewer's {@see ScopeLock}.
 */
final class RequestScope
{
    use ScopesQueries;

    /**
     * @param  list<Filter>  $where
     * @param  array<string, string>  $params  every other scalar query parameter (entity keys, panel controls)
     */
    public function __construct(
        public string $period = '1h',
        public string $from = '',
        public string $to = '',
        public string $service = '',
        public string $environment = '',
        public array $where = [],
        public array $params = [],
    ) {}

    /**
     * Read the scope off a request. Anything the URL does not say falls back
     * to the reader's {@see ViewState} (their remembered window), exactly as a
     * v1 card did on mount.
     */
    public static function fromRequest(Request $request): self
    {
        $state = app(ViewState::class);

        $params = [];

        foreach ($request->query() as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $params[$key] = (string) $value;
            }
        }

        $period = self::string($request->query('period'));

        return new self(
            period: $period !== null && Period::tryFrom($period) !== null ? $period : $state->period()->value,
            from: self::present($request, 'from') ?? $state->from(),
            to: self::present($request, 'to') ?? $state->to(),
            service: self::present($request, 'service') ?? $state->service(),
            environment: self::present($request, 'env') ?? $state->environment(),
            where: Filter::parseAll($request->query('where')),
            params: $params,
        );
    }

    /**
     * A copy with extra entity/control params (used when an entity page runs
     * its v1 detail panels, and in tests).
     *
     * @param  array<string, string>  $params
     */
    public function with(array $params): self
    {
        $clone = clone $this;
        $clone->params = [...$this->params, ...$params];

        return $clone;
    }

    /**
     * @param  list<Filter>  $where
     */
    public function withWhere(array $where): self
    {
        $clone = clone $this;
        $clone->where = $where;

        return $clone;
    }

    public function param(string $key, string $default = ''): string
    {
        return $this->params[$key] ?? $default;
    }

    public function periodEnum(): Period
    {
        return Period::tryFrom($this->period) ?? Period::default();
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public function range(): array
    {
        $from = TimeExpression::parse($this->from);
        $to = TimeExpression::parse($this->to);

        if ($from !== null && $to !== null && $from < $to) {
            return [$from, $to];
        }

        return $this->periodEnum()->range();
    }

    public function rangeSeconds(): int
    {
        [$start, $end] = $this->range();

        return max(1, $end->getTimestamp() - $start->getTimestamp());
    }

    public function promDuration(): string
    {
        return $this->rangeSeconds().'s';
    }

    public function rateWindow(): string
    {
        return Period::windowFor($this->rangeSeconds());
    }

    /** A scoped metric query (see {@see ScopesQueries::metric()}). */
    public function metricQuery(string $name, string $extraMatchers = ''): MetricQuery
    {
        return $this->metric($name, $extraMatchers);
    }

    /** A scoped trace query with extra conditions AND-joined after the scope. */
    public function scopedTraceQuery(TraceCondition ...$conditions): TraceQuery
    {
        return $this->traceQuery(...$conditions);
    }

    /**
     * The service/environment scope as trace conditions.
     *
     * @return list<TraceCondition>
     */
    public function scopeTraceConditions(): array
    {
        return $this->traceConditions();
    }

    /** A scoped log selector (env as a pipeline filter, see {@see ScopesQueries::logSelector()}). */
    public function logQuery(): LogQuery
    {
        return $this->logSelector();
    }

    /** Force the tenancy lock into a hand-written TraceQL query. */
    public function enforce(string $traceql): string
    {
        return $this->enforceScope($traceql);
    }

    /** The scope as a raw TraceQL condition string (for hand-built queries). */
    public function traceScopeString(string $extra = ''): string
    {
        return $this->traceScope($extra);
    }

    /**
     * The scope as query parameters, for building links and for the SPA to
     * round-trip. Filters are omitted; they belong to the view, not the scope.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'period' => $this->period,
            'from' => $this->from,
            'to' => $this->to,
            'service' => $this->service,
            'env' => $this->environment,
        ], static fn (string $value): bool => $value !== '');
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * A parameter the URL states — including an explicit empty one (`?service=`
     * means "all services", which must not fall back to the remembered one;
     * ConvertEmptyStringsToNull turns it into null, hence the has() check).
     */
    private static function present(Request $request, string $key): ?string
    {
        if (! $request->query->has($key)) {
            return null;
        }

        return self::string($request->query($key)) ?? '';
    }
}
