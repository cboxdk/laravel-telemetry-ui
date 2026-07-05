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
}
