<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Explore;

use Cbox\TelemetryUi\Dimensions\Derivation;
use Cbox\TelemetryUi\Dimensions\Dimensions;
use Cbox\TelemetryUi\Http\Api\Filter;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceOp;

/**
 * URL filters → TraceQL conditions through the IR. Filtering by any attribute
 * is native TraceQL (`{ span.hubhus.customer_id = "8655" }`), so this is the
 * whole "every attribute is a facet" engine on the trace side.
 */
final class TraceFilters
{
    /**
     * @param  list<Filter>  $filters
     * @return list<TraceCondition>
     */
    public static function conditions(array $filters, Dimensions $dimensions): array
    {
        $conditions = [];

        foreach ($filters as $filter) {
            $condition = self::condition($filter, $dimensions);

            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        return $conditions;
    }

    public static function condition(Filter $filter, Dimensions $dimensions): ?TraceCondition
    {
        $op = TraceOp::tryFrom($filter->op);

        if ($op === null) {
            return null;
        }

        // Intrinsics take bare tokens: status = error, duration > 250ms, kind = server.
        return match ($filter->key) {
            'status' => in_array($filter->value, ['error', 'ok', 'unset'], true)
                ? TraceCondition::token('status', self::eqOnly($op), $filter->value)
                : null,
            'kind' => in_array($filter->value, ['server', 'client', 'internal', 'producer', 'consumer', 'unspecified'], true)
                ? TraceCondition::token('kind', self::eqOnly($op), $filter->value)
                : null,
            'duration' => preg_match('/^\d+(\.\d+)?(ns|us|µs|ms|s|m|h)?$/', $filter->value) === 1
                ? TraceCondition::token('duration', $op, preg_match('/[a-zµ]$/', $filter->value) === 1 ? $filter->value : $filter->value.'ms')
                : null,
            'name' => new TraceCondition('name', $op, $filter->value),
            default => self::attribute($filter, $op, $dimensions),
        };
    }

    private static function attribute(Filter $filter, TraceOp $op, Dimensions $dimensions): TraceCondition
    {
        $dimension = $dimensions->resolve($filter->key);

        // A derived dimension is a slice of another attribute: ask the backend
        // about that one instead, exactly (`screen = "checkout"` becomes
        // `http.route = "hubhus:checkout"`), so nothing is filtered read-side.
        if ($dimension->derived !== null) {
            return self::derived($filter, $op, $dimensions->resolve($dimension->derived->from)->traceField(), $dimension->derived);
        }

        $field = $dimension->traceField();

        // Numbers and booleans compare as tokens (Tempo is strictly typed:
        // `status_code = 500` matches an int attribute, `= "500"` does not).
        // Regex operators always take a string literal.
        $bare = in_array($op, [TraceOp::Eq, TraceOp::Neq, TraceOp::Gt, TraceOp::Gte, TraceOp::Lt, TraceOp::Lte], true)
            && (preg_match('/^-?\d+(\.\d+)?$/', $filter->value) === 1 || in_array($filter->value, ['true', 'false'], true))
            && ! self::stringKey($filter->key, $dimensions);

        if ($filter->value === '' && ($op === TraceOp::Eq || $op === TraceOp::Neq)) {
            // `key=` means "absent", `key!=` means "present".
            return TraceCondition::nil($field, $op === TraceOp::Eq ? TraceOp::Eq : TraceOp::Neq);
        }

        return $bare
            ? TraceCondition::token($field, $op, $filter->value)
            : new TraceCondition($field, $op, $filter->value);
    }

    /**
     * Identifiers that merely look numeric (customer ids, user ids) are
     * emitted as strings by most apps; a declared dimension can say so with
     * `format: 'string'`, and ids ending in `.id`/`_id` are assumed strings.
     */
    private static function stringKey(string $key, Dimensions $dimensions): bool
    {
        $format = $dimensions->get($key)?->format;

        if ($format === 'string') {
            return true;
        }

        if ($format === 'number' || $format === 'status') {
            return false;
        }

        return str_ends_with($key, '.id') || str_ends_with($key, '_id');
    }

    private static function derived(Filter $filter, TraceOp $op, string $field, Derivation $derived): TraceCondition
    {
        // `screen=` / `screen!=` with no value ask whether the dimension is
        // there at all: any source value shaped like the pattern.
        if ($filter->value === '') {
            return new TraceCondition($field, $op === TraceOp::Neq ? TraceOp::Re : TraceOp::Nre, $derived->regex());
        }

        return match ($op) {
            TraceOp::Eq, TraceOp::Neq => new TraceCondition($field, $op, $derived->encode($filter->value)),
            // The reader's own regex applies to the value, not to the encoding.
            TraceOp::Re, TraceOp::Nre => new TraceCondition($field, $op, $derived->regex('(?:'.$filter->value.')')),
            default => new TraceCondition($field, $op, $derived->encode($filter->value)),
        };
    }

    private static function eqOnly(TraceOp $op): TraceOp
    {
        return $op === TraceOp::Neq ? TraceOp::Neq : TraceOp::Eq;
    }
}
