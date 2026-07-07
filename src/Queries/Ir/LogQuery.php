<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Ir;

use Cbox\TelemetryUi\Cards\Concerns\ScopesQueries;

/**
 * A backend-neutral log query: a stream selector plus an ordered pipeline of
 * filter stages. Cards build one via {@see ScopesQueries::logSelector()}
 * and the fluent helpers here; each logs driver compiles it to its own dialect
 * (LogQL for Loki, SQL for a ClickHouse store).
 *
 * {@see raw()} carries a hand-written dialect string verbatim for the rare
 * caller that needs full expressiveness (the LogQL MCP tool); a SQL backend may
 * reject it.
 */
final readonly class LogQuery
{
    /**
     * @param  list<LabelMatcher>  $stream
     * @param  list<LogStage>  $pipeline
     */
    public function __construct(
        public array $stream = [],
        public array $pipeline = [],
        public ?string $raw = null,
    ) {}

    /**
     * A verbatim dialect query (escape hatch). Backends that can't parse the
     * native dialect should raise rather than guess.
     */
    public static function raw(string $query): self
    {
        return new self(raw: $query);
    }

    public static function stream(LabelMatcher ...$matchers): self
    {
        return new self(array_values($matchers));
    }

    public function pipe(LogStage ...$stages): self
    {
        return new self($this->stream, [...$this->pipeline, ...array_values($stages)], $this->raw);
    }

    public function lineContains(string $value): self
    {
        return $this->pipe(new LineFilter(LineOp::Contains, $value));
    }

    public function lineMatches(string $pattern): self
    {
        return $this->pipe(new LineFilter(LineOp::Regex, $pattern));
    }

    public function whereLabel(string $label, MatchOp $op, string $value): self
    {
        return $this->pipe(new LabelFilter([new LabelMatcher($label, $op, $value)]));
    }

    /**
     * A stable, backend-neutral identity for caching/memoisation.
     */
    public function key(): string
    {
        if ($this->raw !== null) {
            return 'raw:'.$this->raw;
        }

        $parts = [];

        foreach ($this->stream as $m) {
            $parts[] = 's:'.$m->label.$m->op->value.$m->value;
        }

        foreach ($this->pipeline as $stage) {
            if ($stage instanceof LineFilter) {
                $parts[] = 'l:'.$stage->op->value.$stage->value;
            } elseif ($stage instanceof LabelFilter) {
                $matchers = array_map(
                    static fn (LabelMatcher $m): string => $m->label.$m->op->value.$m->value,
                    $stage->matchers,
                );
                $parts[] = 'f:'.($stage->or ? 'or' : 'and').':'.implode(',', $matchers);
            }
        }

        return implode('|', $parts);
    }
}
