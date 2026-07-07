<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Ir;

/**
 * A single label/attribute matcher: `label <op> "value"`. The value is the
 * semantic, UNESCAPED content (for a multi-value scope it is already an RE2
 * alternation) — each dialect compiler quotes and escapes it as needed.
 */
final readonly class LabelMatcher
{
    public function __construct(
        public string $label,
        public MatchOp $op,
        public string $value,
    ) {}

    public static function eq(string $label, string $value): self
    {
        return new self($label, MatchOp::Eq, $value);
    }

    public static function re(string $label, string $value): self
    {
        return new self($label, MatchOp::Re, $value);
    }
}
