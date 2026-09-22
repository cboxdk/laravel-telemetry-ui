<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the pre-built SPA (content-hashed chunks under `public/build/`)
 * straight from the package, so installing never requires publishing or an
 * npm build. Hashed files are immutable, so they cache forever.
 */
final class AssetController
{
    private const TYPES = [
        'js' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'json' => 'application/json',
        'map' => 'application/json',
    ];

    public function __invoke(string $path): BinaryFileResponse
    {
        abort_if(str_contains($path, '..'), 404);

        $root = realpath(self::root());
        $file = realpath(self::root().'/'.$path);

        abort_unless($root !== false && $file !== false && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file), 404);

        $type = self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;

        abort_if($type === null, 404);

        return new BinaryFileResponse($file, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ], true, null, false, false);
    }

    public static function root(): string
    {
        return dirname(__DIR__, 3).'/public/build';
    }
}
