<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Http\Controllers;

use Cbox\TelemetryUi\Events\DashboardViewed;
use Cbox\TelemetryUi\Http\Api\Brand;
use Cbox\TelemetryUi\Support\ViewState;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The SPA shell: one HTML document for every dashboard URL. It carries the
 * few facts the client cannot discover on its own — where it is mounted, where
 * the API and the hashed chunks live, the CSRF token — in a JSON bootstrap
 * block, then hands over to the React app. Everything else comes from the API.
 */
final class SpaController
{
    public function __invoke(Request $request): Response
    {
        $base = '/'.trim((string) config('telemetry-ui.path', 'telemetry-ui'), '/');
        $base = $base === '/' ? '' : $base;

        $manifest = self::manifest();
        $entry = $manifest['index.html'] ?? $manifest['src/main.tsx'] ?? null;

        $state = app(ViewState::class);

        event(new DashboardViewed(Auth::user(), self::pageOf($request), $state->service(), $state->environment()));

        $boot = [
            'base' => $base,
            'api' => $base.'/api/v2',
            'assets' => $base.'/build',
            'csrf' => $request->hasSession() ? $request->session()->token() : csrf_token(),
            'brand' => Brand::toArray(),
        ];

        $title = e($boot['brand']['name']);
        $json = str_replace('</', '<\/', (string) json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP));
        $assets = $boot['assets'];

        $css = '';
        $js = '';

        if (is_array($entry)) {
            foreach ((array) ($entry['css'] ?? []) as $file) {
                $css .= '<link rel="stylesheet" href="'.e($assets.'/'.$file).'">';
            }

            // Preload the entry's static imports (react, router chunks) so they
            // download in parallel with the entry instead of after it parses.
            foreach ((array) ($entry['imports'] ?? []) as $import) {
                $file = is_string($import) ? ($manifest[$import]['file'] ?? null) : null;

                if (is_string($file)) {
                    $css .= '<link rel="modulepreload" href="'.e($assets.'/'.$file).'">';
                }
            }

            $js = '<script type="module" src="'.e($assets.'/'.(string) ($entry['file'] ?? '')).'"></script>';
        }

        $missing = $entry === null
            ? '<p style="font:14px system-ui;padding:2rem">telemetry-ui: the SPA build is missing (public/build). Run <code>npm run build</code>.</p>'
            : '';

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$title}</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23304ba8'/%3E%3Cpath d='M6 17h5l3-8 4 14 3-6h5' fill='none' stroke='white' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
<script>(function(){try{var t=localStorage.getItem('tui:theme');var d=t?t==='dark':matchMedia('(prefers-color-scheme: dark)').matches;if(d)document.documentElement.classList.add('dark')}catch(e){}})();</script>
{$css}
</head>
<body>
<div id="app">{$missing}</div>
<script type="application/json" id="telemetry-ui-boot">{$json}</script>
{$js}
</body>
</html>
HTML;

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'same-origin',
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function manifest(): array
    {
        $path = AssetController::root().'/.vite/manifest.json';

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        /** @var array<string, array<string, mixed>> */
        return is_array($decoded) ? $decoded : [];
    }

    private static function pageOf(Request $request): string
    {
        $any = $request->route()?->parameter('any');

        if (! is_string($any) || $any === '') {
            return 'dashboard';
        }

        $segments = explode('/', trim($any, '/'));

        return match ($segments[0]) {
            'p' => $segments[1] ?? 'dashboard',
            'explore' => $segments[1] ?? 'requests',
            default => $segments[0],
        };
    }
}
