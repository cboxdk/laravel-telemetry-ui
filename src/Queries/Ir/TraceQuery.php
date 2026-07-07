<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Ir;

use Cbox\TelemetryUi\Cards\Concerns\ScopesQueries;

/**
 * A backend-neutral trace search: an AND-joined set of span conditions plus an
 * optional list of span/resource fields to `select`. Cards build one from the
 * scope helpers on {@see ScopesQueries} and the
 * fluent helpers here; each traces driver compiles it to its own dialect
 * (TraceQL for Tempo, SQL for a ClickHouse store).
 *
 * {@see raw()} carries a hand-written spanset verbatim for the raw `?q=` search
 * box (already scope-enforced as a string); a SQL backend may reject it.
 */
final readonly class TraceQuery
{
    /**
     * @param  list<TraceCondition>  $conditions
     * @param  list<string>  $select
     */
    public function __construct(
        public array $conditions = [],
        public array $select = [],
        public ?string $raw = null,
    ) {}

    /**
     * A verbatim dialect spanset (escape hatch). Backends that can't parse the
     * native dialect should raise rather than guess.
     */
    public static function raw(string $query): self
    {
        return new self(raw: $query);
    }

    public function where(TraceCondition ...$conditions): self
    {
        return new self([...$this->conditions, ...array_values($conditions)], $this->select, $this->raw);
    }

    public function select(string ...$fields): self
    {
        return new self($this->conditions, [...$this->select, ...array_values($fields)], $this->raw);
    }

    public function hasSelect(): bool
    {
        return $this->select !== [];
    }

    /**
     * A stable, backend-neutral identity for caching/memoisation.
     */
    public function key(): string
    {
        if ($this->raw !== null) {
            return 'raw:'.$this->raw;
        }

        $conditions = array_map(
            static fn (TraceCondition $c): string => $c->field.$c->op->value.($c->quoted ? '"'.$c->value.'"' : $c->value),
            $this->conditions,
        );

        return implode(' && ', $conditions).'|select:'.implode(',', $this->select);
    }
}
