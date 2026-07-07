<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Compilers;

use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;

/**
 * Compiles a {@see TraceQuery} to a TraceQL string for Tempo. Conditions are
 * AND-joined inside a single spanset with Tempo's conventional spacing
 * (`field = "value"`); string literals are quoted and `"`/`\` escaped here, so
 * callers pass raw, unescaped values. Verbatim tokens (`status = error`,
 * `duration > 100ms`, `... != nil`) are emitted as-is.
 */
final class TraceqlCompiler
{
    public function compile(TraceQuery $query): string
    {
        if ($query->raw !== null) {
            return $query->raw;
        }

        $conditions = array_map($this->condition(...), $query->conditions);

        $spanset = $conditions === [] ? '{}' : '{ '.implode(' && ', $conditions).' }';

        if ($query->select !== []) {
            $spanset .= ' | select('.implode(', ', $query->select).')';
        }

        return $spanset;
    }

    private function condition(TraceCondition $condition): string
    {
        $value = $condition->quoted
            ? '"'.addcslashes($condition->value, '"\\').'"'
            : $condition->value;

        return $condition->field.' '.$condition->op->value.' '.$value;
    }
}
