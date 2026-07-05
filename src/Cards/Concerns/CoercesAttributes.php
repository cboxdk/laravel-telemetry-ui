<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Cards\Concerns;

/**
 * Tolerant coercion of raw span/log attribute values (which arrive as `mixed`
 * from the backend JSON) into the scalars a card row needs — the shared reading
 * layer for cards that build tables straight from attribute bags.
 */
trait CoercesAttributes
{
    /**
     * A non-empty string, or null — for optional display columns.
     */
    protected function str(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * A float, defaulting to 0.0 for anything non-numeric — for metric columns.
     */
    protected function num(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
