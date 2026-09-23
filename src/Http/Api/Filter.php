<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Api;

/**
 * One active dimension filter from the URL — `where[]=billing.customer_id=8655`.
 *
 * The URL *is* the query: every filter chip in the SPA is one of these, so a
 * view is shareable and back/forward works. The wire format is `key<op>value`
 * with the operator being the first operator token after the key (keys never
 * contain `=`, `!`, `<`, `>` or `~`, values may contain anything).
 */
final readonly class Filter
{
    public const OPS = ['!=', '=~', '!~', '>=', '<=', '=', '>', '<'];

    public function __construct(
        public string $key,
        public string $op,
        public string $value,
    ) {}

    public static function parse(string $raw): ?self
    {
        if (preg_match('/^([^=!<>~\s]+)\s*(!=|=~|!~|>=|<=|=|>|<)(.*)$/s', trim($raw), $m) !== 1) {
            return null;
        }

        return new self($m[1], $m[2], $m[3]);
    }

    /**
     * @param  mixed  $raw  the `where` query parameter (list of strings, or one string)
     * @return list<self>
     */
    public static function parseAll(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : ($raw === null ? [] : [$raw]);
        $filters = [];

        foreach ($values as $value) {
            if (is_string($value) && ($filter = self::parse($value)) !== null) {
                $filters[] = $filter;
            }
        }

        return $filters;
    }

    public function negated(): bool
    {
        return $this->op === '!=' || $this->op === '!~';
    }

    public function toString(): string
    {
        return $this->key.$this->op.$this->value;
    }

    /**
     * Whether a raw attribute value satisfies this filter — used where results
     * are post-filtered read-side (log records, error groups, samples).
     */
    public function matches(mixed $actual): bool
    {
        $actual = is_scalar($actual) ? (string) $actual : ($actual === null ? '' : (string) json_encode($actual));

        return match ($this->op) {
            '=' => $actual === $this->value,
            '!=' => $actual !== $this->value,
            '=~' => @preg_match('~'.str_replace('~', '\~', $this->value).'~u', $actual) === 1,
            '!~' => @preg_match('~'.str_replace('~', '\~', $this->value).'~u', $actual) !== 1,
            '>' => is_numeric($actual) && is_numeric($this->value) && (float) $actual > (float) $this->value,
            '>=' => is_numeric($actual) && is_numeric($this->value) && (float) $actual >= (float) $this->value,
            '<' => is_numeric($actual) && is_numeric($this->value) && (float) $actual < (float) $this->value,
            '<=' => is_numeric($actual) && is_numeric($this->value) && (float) $actual <= (float) $this->value,
            default => false,
        };
    }
}
