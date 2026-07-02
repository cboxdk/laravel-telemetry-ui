<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/**
 * Serves the pre-built dashboard assets straight from the package, so
 * installing the package never requires publishing or an npm build.
 */
final class AssetController
{
    private const ASSETS = [
        'telemetry-ui.js' => 'application/javascript; charset=utf-8',
        'telemetry-ui.css' => 'text/css; charset=utf-8',
    ];

    public function __invoke(string $asset): Response
    {
        abort_unless(isset(self::ASSETS[$asset]), 404);

        $path = dirname(__DIR__, 3).'/public/'.$asset;

        abort_unless(File::exists($path), 404);

        return new Response(File::get($path), 200, [
            'Content-Type' => self::ASSETS[$asset],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
