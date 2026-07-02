<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Support;

/**
 * Cache-busting versions for the pre-built assets in the package's public/
 * directory (served with immutable cache headers by AssetController).
 */
final class Assets
{
    public static function version(string $asset): int
    {
        $mtime = @filemtime(dirname(__DIR__, 2).'/public/'.$asset);

        return $mtime === false ? 0 : $mtime;
    }
}
