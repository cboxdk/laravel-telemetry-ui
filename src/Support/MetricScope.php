<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

use Cbox\TelemetryUi\Cards\Concerns\ScopesQueries;

/**
 * The PromQL label matchers for the current viewer's scope selection — the same
 * `service_name` / `deployment_environment_name` matchers the card query engine
 * ({@see ScopesQueries}) puts on every metric.
 *
 * Schema detection uses it so "does this schema family exist?" becomes "does the
 * SELECTED service emit it?" — pick a service without Statamic and its sidebar
 * group drops, instead of lingering because some *other* service has it.
 *
 * Bounded by the tenancy {@see ScopeLock}, with the same fail-closed semantics
 * as the query engine: a selection may only narrow within the allowed set, a
 * blank or out-of-bounds selection can't widen, and a dimension locked to an
 * empty set matches nothing (so a locked-out viewer never sees the group).
 */
final readonly class MetricScope
{
    /**
     * Locked-to-nothing sentinel: a service name no real emitter carries, so the
     * detection count matches nothing rather than widening to the whole fleet.
     * Mirrors the query engine's own sentinel.
     */
    private const NO_SCOPE = '__telemetry_ui_no_scope__';

    public function __construct(private ScopeLock $lock) {}

    /**
     * Comma-joined matchers for the selection, e.g.
     * `service_name="checkout",deployment_environment_name="prod"`. Empty when
     * nothing narrows (All services / All envs, unlocked) — detection then asks
     * about the whole fleet, exactly as it did before scoping existed.
     */
    public function promMatchers(string $service, string $environment): string
    {
        $matchers = array_filter([
            $this->matcher(
                'service_name',
                $this->effective($service, $this->lock->services(), $this->lock->servicesLocked()),
                $this->lock->servicesLocked(),
            ),
            $this->matcher(
                'deployment_environment_name',
                $this->effective($environment, $this->lock->environments(), $this->lock->environmentsLocked()),
                $this->lock->environmentsLocked(),
            ),
        ], static fn (?string $matcher): bool => $matcher !== null);

        return implode(',', $matchers);
    }

    /**
     * The effective values to scope by: locked, a selection may only narrow
     * within the allowed set (blank/out-of-bounds falls back to the whole set);
     * unlocked, the selection alone scopes and blank means unrestricted.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function effective(string $selected, array $allowed, bool $locked): array
    {
        if ($locked) {
            return $selected !== '' && in_array($selected, $allowed, true) ? [$selected] : $allowed;
        }

        return $selected !== '' ? [$selected] : [];
    }

    /**
     * Exact matcher for one value, an RE2 alternation for several; nothing when
     * unrestricted, or the matches-nothing sentinel when locked to an empty set.
     *
     * @param  list<string>  $values
     */
    private function matcher(string $label, array $values, bool $locked): ?string
    {
        return match (count($values)) {
            0 => $locked ? $label.'="'.self::NO_SCOPE.'"' : null,
            1 => $label.'="'.addcslashes($values[0], '"\\').'"',
            default => $label.'=~"'.$this->alternation($values).'"',
        };
    }

    /**
     * A literal RE2 alternation ("a|b") of the values, each metacharacter
     * escaped so it matches literally.
     *
     * @param  list<string>  $values
     */
    private function alternation(array $values): string
    {
        $meta = [
            '\\' => '\\\\', '.' => '\\.', '+' => '\\+', '*' => '\\*', '?' => '\\?',
            '(' => '\\(', ')' => '\\)', '[' => '\\[', ']' => '\\]', '{' => '\\{',
            '}' => '\\}', '^' => '\\^', '$' => '\\$', '|' => '\\|',
        ];

        return implode('|', array_map(static fn (string $value): string => strtr($value, $meta), $values));
    }
}
