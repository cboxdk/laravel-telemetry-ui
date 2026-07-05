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

    /**
     * The stylesheet + script tags for the dashboard bundle, cache-busted — so
     * a host page can load them once (via the @telemetryUiAssets directive)
     * before embedding cards as widgets. Livewire/Alpine are the host's own.
     */
    public static function tags(): string
    {
        $css = route('telemetry-ui.asset', ['asset' => 'telemetry-ui.css', 'v' => self::version('telemetry-ui.css')]);
        $js = route('telemetry-ui.asset', ['asset' => 'telemetry-ui.js', 'v' => self::version('telemetry-ui.js')]);

        return '<link rel="stylesheet" href="'.e($css).'">'."\n".'<script src="'.e($js).'" defer></script>';
    }
}
