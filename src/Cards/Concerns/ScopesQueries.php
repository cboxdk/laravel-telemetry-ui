<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Concerns;

use Cbox\TelemetryUi\Cards\Card;
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
     * A metric reference with the current scope (and any extra matchers)
     * applied: `metric{service_name="checkout",deployment_environment_name="prod"}`.
     */
    protected function metric(string $name, string $extraMatchers = ''): string
    {
        $lock = app(ScopeLock::class);

        $matchers = array_values(array_filter([
            $this->labelMatcher('service_name', $this->scopedServices(), $lock->servicesLocked()),
            $this->labelMatcher('deployment_environment_name', $this->scopedEnvironments(), $lock->environmentsLocked()),
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
     * A Loki stream selector for the current scope. Loki requires at least
     * one non-empty matcher, so an unscoped selector matches any service.
     */
    protected function logSelector(string $extraMatchers = ''): string
    {
        $lock = app(ScopeLock::class);

        $matchers = array_values(array_filter([
            $this->labelMatcher('service_name', $this->scopedServices(), $lock->servicesLocked()),
            $this->labelMatcher('deployment_environment_name', $this->scopedEnvironments(), $lock->environmentsLocked()),
        ], static fn (?string $m): bool => $m !== null));

        if ($extraMatchers !== '') {
            $matchers[] = $extraMatchers;
        }

        if ($matchers === []) {
            $matchers[] = 'service_name=~".+"';
        }

        return '{'.implode(',', $matchers).'}';
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
     * A PromQL/Loki label matcher for a set of values: exact for one, an RE2
     * alternation for several. For none: nothing when unrestricted, or a
     * matches-nothing sentinel when the dimension is locked to an empty set.
     *
     * @param  list<string>  $values
     */
    private function labelMatcher(string $label, array $values, bool $locked = false): ?string
    {
        return match (count($values)) {
            0 => $locked ? $label.'="'.self::NO_SCOPE.'"' : null,
            1 => $label.'="'.$this->escapeLabelValue($values[0]).'"',
            default => $label.'=~"'.$this->escapeLabelValue($this->alternation($values)).'"',
        };
    }

    /**
     * A TraceQL scope condition for a set of values (see {@see labelMatcher()}).
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
