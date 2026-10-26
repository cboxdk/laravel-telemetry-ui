<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Queries;

/**
 * Constrains every spanset of a hand-written TraceQL query.
 *
 * A raw query can hold more than one spanset — `{ a } || { b }`,
 * `{ a } >> { b }` — and each one selects spans on its own. Putting a
 * condition into the first spanset only leaves the others free to match
 * anything, so a scope that must hold has to go into all of them.
 *
 * The query is read, not pattern-matched: string literals (`"…"` with
 * backslash escapes, and backtick raw strings) are skipped whole, so a brace
 * or a parenthesis inside a value is never mistaken for syntax. A spanset's
 * own parentheses must pair up, so its body cannot close the group the
 * condition is joined to and escape it with `) || (`. Anything that cannot be
 * read with certainty — an unterminated string, a brace or parenthesis that
 * does not pair, a nested brace, anything that could open a comment, no
 * spanset at all — gives null, and the caller fails closed.
 */
final class TraceqlSpansets
{
    /**
     * `{ a } || { }` becomes `{ <condition> && (a) } || { <condition> }`, or
     * null when the query cannot be read with certainty.
     */
    public static function constrain(string $traceql, string $condition): ?string
    {
        $out = '';
        $body = '';
        $inSpanset = false;
        $parens = 0;
        $found = false;
        $length = strlen($traceql);

        for ($i = 0; $i < $length; $i++) {
            $char = $traceql[$i];

            if ($char === '"' || $char === '`') {
                $end = self::stringEnd($traceql, $i);

                if ($end === null) {
                    return null;
                }

                $literal = substr($traceql, $i, $end - $i + 1);

                if ($inSpanset) {
                    $body .= $literal;
                } else {
                    $out .= $literal;
                }

                $i = $end;

                continue;
            }

            // Nothing that could start a comment, outside a string: a comment
            // would hide a parenthesis from the count below but not from the
            // backend, and TraceQL has no use for these sequences otherwise.
            if ($char === '#' || ($char === '/' && in_array($traceql[$i + 1] ?? '', ['/', '*'], true))) {
                return null;
            }

            if ($char === '{') {
                if ($inSpanset) {
                    return null;
                }

                $inSpanset = true;
                $body = '';
                $parens = 0;

                continue;
            }

            if ($char === '}') {
                if (! $inSpanset || $parens !== 0) {
                    return null;
                }

                $inSpanset = false;
                $found = true;
                $inner = trim($body);
                $out .= $inner === '' ? '{ '.$condition.' }' : '{ '.$condition.' && ('.$inner.') }';

                continue;
            }

            if ($inSpanset) {
                if ($char === '(') {
                    $parens++;
                } elseif ($char === ')' && --$parens < 0) {
                    return null;
                }

                $body .= $char;

                continue;
            }

            $out .= $char;
        }

        return $inSpanset || ! $found ? null : $out;
    }

    /**
     * The offset of the quote that closes the string literal opening at
     * $start, or null when it never closes. Double-quoted strings honour
     * backslash escapes; backtick strings are raw.
     */
    private static function stringEnd(string $traceql, int $start): ?int
    {
        $quote = $traceql[$start];
        $length = strlen($traceql);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($quote === '"' && $traceql[$i] === '\\') {
                $i++;

                continue;
            }

            if ($traceql[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }
}
