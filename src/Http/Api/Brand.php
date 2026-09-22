<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Api;

/**
 * The host's white-label settings (`telemetry-ui.brand`), as both the SPA
 * shell and the bootstrap endpoint hand them to the client.
 *
 * The accent ends up in a CSS custom property, so it is reduced to the
 * characters a CSS colour needs — no `;`/`}` to break out of the declaration,
 * no `/` or quotes to smuggle in an external `url(//…)`. Same rule v1's
 * layout applied before it inlined the value.
 */
final class Brand
{
    /**
     * @return array{name: string, logo: string|null, accent: string|null}
     */
    public static function toArray(): array
    {
        $name = config('telemetry-ui.brand.name');
        $logo = config('telemetry-ui.brand.logo');

        return [
            'name' => is_string($name) && $name !== '' ? $name : 'Telemetry',
            'logo' => is_string($logo) && $logo !== '' ? $logo : null,
            'accent' => self::accent(config('telemetry-ui.brand.accent')),
        ];
    }

    public static function accent(mixed $accent): ?string
    {
        if (! is_string($accent)) {
            return null;
        }

        $clean = trim((string) preg_replace('/[^a-zA-Z0-9#(),.%\s-]/', '', $accent));

        return $clean !== '' ? $clean : null;
    }
}
