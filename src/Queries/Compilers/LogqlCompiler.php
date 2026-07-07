<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Compilers;

use Cbox\TelemetryUi\Queries\Ir\LabelFilter;
use Cbox\TelemetryUi\Queries\Ir\LabelMatcher;
use Cbox\TelemetryUi\Queries\Ir\LineFilter;
use Cbox\TelemetryUi\Queries\Ir\LogQuery;
use Cbox\TelemetryUi\Queries\Ir\LogStage;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use InvalidArgumentException;

/**
 * Compiles a {@see LogQuery} to a LogQL string for Loki. Label matchers and
 * line filters are emitted with Loki's conventional spelling; values are quoted
 * and `"`/`\` escaped here, so callers pass raw, unescaped values.
 *
 * Loki requires at least one non-empty stream matcher, so an empty stream
 * selector falls back to `{service_name=~".+"}` (matches any service).
 */
final class LogqlCompiler
{
    public function compile(LogQuery $query): string
    {
        if ($query->raw !== null) {
            return $query->raw;
        }

        $stream = $query->stream === []
            ? [new LabelMatcher('service_name', MatchOp::Re, '.+')]
            : $query->stream;

        $out = '{'.implode(',', array_map($this->matcher(...), $stream)).'}';

        foreach ($query->pipeline as $stage) {
            $out .= $this->stage($stage);
        }

        return $out;
    }

    private function stage(LogStage $stage): string
    {
        return match (true) {
            $stage instanceof LineFilter => ' '.$stage->op->value.' "'.$this->escape($stage->value).'"',
            $stage instanceof LabelFilter => ' | '.implode(
                $stage->or ? ' or ' : ' and ',
                array_map($this->matcher(...), $stage->matchers),
            ),
            default => throw new InvalidArgumentException('Unsupported log stage: '.$stage::class),
        };
    }

    private function matcher(LabelMatcher $matcher): string
    {
        return $matcher->label.$matcher->op->value.'"'.$this->escape($matcher->value).'"';
    }

    private function escape(string $value): string
    {
        return addcslashes($value, '"\\');
    }
}
