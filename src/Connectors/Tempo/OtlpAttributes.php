<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors\Tempo;

/**
 * Flattens OTLP-JSON attribute lists ([{key, value: {stringValue: ...}}]).
 */
final class OtlpAttributes
{
    /**
     * @param  array<array-key, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function parse(array $attributes): array
    {
        $parsed = [];

        foreach ($attributes as $attribute) {
            if (! is_array($attribute) || ! isset($attribute['key']) || ! is_string($attribute['key'])) {
                continue;
            }

            $parsed[$attribute['key']] = self::value(
                is_array($attribute['value'] ?? null) ? $attribute['value'] : [],
            );
        }

        return $parsed;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    public static function value(array $value): mixed
    {
        if (array_key_exists('stringValue', $value)) {
            return (string) $value['stringValue'];
        }

        if (array_key_exists('intValue', $value)) {
            return (int) $value['intValue'];
        }

        if (array_key_exists('doubleValue', $value)) {
            return (float) $value['doubleValue'];
        }

        if (array_key_exists('boolValue', $value)) {
            return (bool) $value['boolValue'];
        }

        if (is_array($value['arrayValue'] ?? null)) {
            $values = is_array($value['arrayValue']['values'] ?? null) ? $value['arrayValue']['values'] : [];

            return array_map(
                static fn (mixed $item): mixed => self::value(is_array($item) ? $item : []),
                $values,
            );
        }

        if (is_array($value['kvlistValue'] ?? null)) {
            $entries = is_array($value['kvlistValue']['values'] ?? null) ? $value['kvlistValue']['values'] : [];

            return self::parse($entries);
        }

        return null;
    }
}
