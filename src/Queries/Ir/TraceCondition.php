<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries\Ir;

/**
 * One condition inside a {@see TraceQuery} spanset: `<field> <op> <value>`.
 *
 * A value is either a quoted string literal (`span.http.route = "checkout"`) or
 * a verbatim token (`status = error`, `kind = server`, `span.browser = true`,
 * `duration > 100ms`, `span.http.response.status_code >= 500`, `... != nil`).
 * The {@see $quoted} flag decides which; the compiler quotes and escapes string
 * literals and emits tokens as-is.
 */
final readonly class TraceCondition
{
    /**
     * @param  list<TraceCondition>  $or  alternatives: this condition or any of these
     */
    public function __construct(
        public string $field,
        public TraceOp $op,
        public string $value,
        public bool $quoted = true,
        public array $or = [],
    ) {}

    /**
     * Any one of the conditions (`(a || b || c)`) — a recipient in To, Cc or
     * Bcc. Still a single span's conditions: the alternatives are on the
     * same span.
     */
    public static function any(TraceCondition $first, TraceCondition ...$rest): self
    {
        return new self($first->field, $first->op, $first->value, $first->quoted, [...$first->or, ...array_values($rest)]);
    }

    /** A quoted string-literal condition (`field = "value"`). */
    public static function eq(string $field, string $value): self
    {
        return new self($field, TraceOp::Eq, $value);
    }

    public static function neq(string $field, string $value): self
    {
        return new self($field, TraceOp::Neq, $value);
    }

    public static function re(string $field, string $value): self
    {
        return new self($field, TraceOp::Re, $value);
    }

    /** A verbatim-token condition (`status = error`, `duration > 100ms`, `field != nil`). */
    public static function token(string $field, TraceOp $op, string $token): self
    {
        return new self($field, $op, $token, quoted: false);
    }

    /** `field <op> nil` — a presence/absence check (TraceQL can't read a missing attr). */
    public static function nil(string $field, TraceOp $op = TraceOp::Neq): self
    {
        return self::token($field, $op, 'nil');
    }
}
