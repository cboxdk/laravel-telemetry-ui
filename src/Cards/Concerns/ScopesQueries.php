<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Concerns;

use Cbox\TelemetryUi\Cards\Card;
use Cbox\TelemetryUi\Queries\Ir\LabelFilter;
use Cbox\TelemetryUi\Queries\Ir\LabelMatcher;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Ir\MetricQuery;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Support\ScopeLock;

/**
 * The query-scoping engine shared by every {@see Card}:
 * turns the active service/environment selection (bounded by the per-viewer
 * tenancy lock) into scoped PromQL, TraceQL and LogQL — so a card never builds
 * label matchers by hand and can't widen past its lock.
 */
trait ScopesQueries
{
    /**
     * Emitted for a dimension that is locked to an empty allowed set — a value
     * no real service/environment carries, so the query matches nothing (fail
     * closed) instead of widening to the whole fleet.
     */
    private const NO_SCOPE = '__telemetry_ui_no_scope__';

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
     * A scoped {@see MetricQuery} for a metric — the current scope (plus any
     * entity {@see scopeMatchers()} and extra matchers) as the selector, ready
     * for the fluent rate/increase/quantile/aggregation helpers. Compiles to
     * `metric{service_name="checkout",deployment_environment_name="prod"}`.
     */
    protected function metric(string $name, string $extraMatchers = ''): MetricQuery
    {
        $lock = app(ScopeLock::class);

        $matchers = array_values(array_filter([
            $this->scopeLabelMatcher('service_name', $this->scopedServices(), $lock->servicesLocked()),
            $this->scopeLabelMatcher('deployment_environment_name', $this->scopedEnvironments(), $lock->environmentsLocked()),
        ], static fn (?LabelMatcher $m): bool => $m !== null));

        $raw = [];

        if (($scope = $this->scopeMatchers()) !== '') {
            $raw[] = $scope;
        }

        if ($extraMatchers !== '') {
            $raw[] = $extraMatchers;
        }

        return new MetricQuery($name, $matchers, $raw);
    }

    /**
     * TraceQL conditions for the current scope, AND-joined with any extra
     * conditions: `resource.service.name = "checkout" && <extra>`. String form,
     * kept for {@see enforceScope()} and hand-built raw queries; structured
     * cards use {@see TraceQuery()} instead.
     */
    protected function traceScope(string $extraConditions = ''): string
    {
        $lock = app(ScopeLock::class);

        $conditions = array_values(array_filter([
            $this->traceMatcher('resource.service.name', $this->scopedServices(), $lock->servicesLocked()),
            $this->traceMatcher('resource.deployment.environment.name', $this->scopedEnvironments(), $lock->environmentsLocked()),
        ], static fn (?string $c): bool => $c !== null));

        if ($extraConditions !== '') {
            $conditions[] = $extraConditions;
        }

        return implode(' && ', $conditions);
    }

    /**
     * A scoped {@see TraceQuery} for the current service/environment, with any
     * extra conditions AND-joined after the scope — the structured replacement
     * for `'{ '.traceScope($extra).' }'`.
     */
    protected function traceQuery(TraceCondition ...$conditions): TraceQuery
    {
        return new TraceQuery([...$this->traceConditions(), ...array_values($conditions)]);
    }

    /**
     * The current scope as TraceQL conditions.
     *
     * @return list<TraceCondition>
     */
    protected function traceConditions(): array
    {
        $lock = app(ScopeLock::class);

        return array_values(array_filter([
            $this->traceScopeMatcher('resource.service.name', $this->scopedServices(), $lock->servicesLocked()),
            $this->traceScopeMatcher('resource.deployment.environment.name', $this->scopedEnvironments(), $lock->environmentsLocked()),
        ], static fn (?TraceCondition $c): bool => $c !== null));
    }

    /**
     * A {@see TraceCondition} for a scoped dimension (see {@see traceMatcher()}).
     *
     * @param  list<string>  $values
     */
    private function traceScopeMatcher(string $key, array $values, bool $locked): ?TraceCondition
    {
        return match (count($values)) {
            0 => $locked ? TraceCondition::eq($key, self::NO_SCOPE) : null,
            1 => TraceCondition::eq($key, $values[0]),
            default => TraceCondition::re($key, $this->alternation($values)),
        };
    }

    /**
     * Force the current scope into an already-built, hand-supplied TraceQL
     * query — the traces page runs `?q=` verbatim, so a locked viewer's raw or
     * deep-linked query must still be constrained to their services. Injects the
     * scope into the primary `{ … }` spanset; a no-op when no lock is active
     * (a plain selection stays a convenience filter, not a boundary).
     */
    protected function enforceScope(string $traceql): string
    {
        if (! app(ScopeLock::class)->restricted()) {
            return $traceql;
        }

        $scope = $this->traceScope();

        if ($scope === '') {
            return $traceql;
        }

        $scoped = preg_replace_callback(
            '/\{\s*(.*?)\s*\}/s',
            static fn (array $m): string => $m[1] === '' ? '{ '.$scope.' }' : '{ '.$scope.' && ('.$m[1].') }',
            $traceql,
            limit: 1,
        );

        return $scoped ?? $traceql;
    }

    /**
     * A scoped {@see LogQuery} for the current service/environment: the
     * environment is applied as a pipeline label filter rather than a
     * stream-label matcher.
     *
     * Only `service_name` goes in the stream selector: it is the one label a
     * telemetry backend is guaranteed to index as a *stream label*. The
     * environment is emitted as a trailing `| deployment_environment_name="…"`
     * label filter instead — many Loki deployments (e.g. otel-lgtm) index only
     * service_name as a stream label and carry `deployment_environment_name` as
     * *structured metadata*. A stream-selector matcher on a non-indexed label
     * silently matches nothing (so every env-scoped log card returns 0 rows),
     * whereas a pipeline label filter matches whether the label is a stream label
     * or structured metadata — correct in both cases.
     *
     * Callers append further pipeline stages via the fluent {@see LogQuery}
     * helpers, which chain onto the env filter. Loki requires at least one
     * non-empty stream matcher, so an unscoped selector still matches any
     * service (the compiler falls back to `{service_name=~".+"}`).
     */
    protected function logSelector(): LogQuery
    {
        $lock = app(ScopeLock::class);

        $stream = array_values(array_filter([
            $this->scopeLabelMatcher('service_name', $this->scopedServices(), $lock->servicesLocked()),
        ], static fn (?LabelMatcher $m): bool => $m !== null));

        $query = LogQuery::stream(...$stream);

        $env = $this->scopeLabelMatcher('deployment_environment_name', $this->scopedEnvironments(), $lock->environmentsLocked());

        return $env !== null ? $query->pipe(new LabelFilter([$env])) : $query;
    }

    /**
     * A {@see LabelMatcher} for a scoped dimension (PromQL/LogQL): exact for one
     * value, an RE2 alternation for several, a matches-nothing sentinel when
     * locked to an empty set, or null when unrestricted.
     *
     * @param  list<string>  $values
     */
    private function scopeLabelMatcher(string $label, array $values, bool $locked): ?LabelMatcher
    {
        return match (count($values)) {
            0 => $locked ? new LabelMatcher($label, MatchOp::Eq, self::NO_SCOPE) : null,
            1 => new LabelMatcher($label, MatchOp::Eq, $values[0]),
            default => new LabelMatcher($label, MatchOp::Re, $this->alternation($values)),
        };
    }

    protected function escapeLabelValue(string $value): string
    {
        return addcslashes($value, '"\\');
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
        $lock = app(ScopeLock::class);

        return $this->applyLock($this->service, $lock->services(), $lock->servicesLocked());
    }

    /**
     * @return list<string>
     */
    private function scopedEnvironments(): array
    {
        $lock = app(ScopeLock::class);

        return $this->applyLock($this->environment, $lock->environments(), $lock->environmentsLocked());
    }

    /**
     * The effective values to scope by. When locked, the allowed set is
     * authoritative: a selection may only narrow within it (an out-of-bounds or
     * blank `?service=` can't widen), and an empty allowed set stays empty
     * (locked to nothing). When not locked, the selection alone is the scope,
     * and blank means unrestricted.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function applyLock(string $selected, array $allowed, bool $locked): array
    {
        if ($locked) {
            return $selected !== '' && in_array($selected, $allowed, true) ? [$selected] : $allowed;
        }

        return $selected !== '' ? [$selected] : [];
    }

    /**
     * A TraceQL scope condition for a set of values (see {@see LabelMatcher()}).
     *
     * @param  list<string>  $values
     */
    private function traceMatcher(string $key, array $values, bool $locked = false): ?string
    {
        return match (count($values)) {
            0 => $locked ? $key.' = "'.self::NO_SCOPE.'"' : null,
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
}
