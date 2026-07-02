<?php

declare(strict_types=1);
use Cbox\TelemetryUi\Cards\Builtin\RequestsOverview;

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. When disabled the package registers no routes and no
    | Livewire components, making it completely inert (e.g. in queue
    | workers or environments where the dashboard should not exist).
    |
    */

    'enabled' => (bool) env('TELEMETRY_UI_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    |
    | The path prefix and optional domain the dashboard is served from.
    | Note: the default deliberately avoids "telemetry" to not clash with
    | the /telemetry/metrics Prometheus scrape endpoint registered by
    | cboxdk/laravel-telemetry.
    |
    */

    'path' => env('TELEMETRY_UI_PATH', 'telemetry-ui'),

    'domain' => env('TELEMETRY_UI_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to all dashboard routes. The package always appends
    | its own Authorize middleware, which checks the "viewTelemetryUi" gate.
    | Out of the box the gate only allows access in the local environment;
    | define the gate in your app to open it up elsewhere.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Named connections to your observability backends. The keys "metrics",
    | "traces" and "logs" are the defaults used when no explicit connection
    | name is requested; additional named connections may be added and
    | requested explicitly (e.g. a per-tenant Mimir connection).
    |
    | Drivers: prometheus, mimir (metrics) — tempo (traces) — loki (logs).
    | Mimir is the Prometheus API served under a path prefix (default
    | "/prometheus") with multi-tenancy via the X-Scope-OrgID header, which
    | Tempo and Loki honour as well when "tenant" is set.
    |
    */

    'connections' => [

        'metrics' => [
            'driver' => env('TELEMETRY_UI_METRICS_DRIVER', 'prometheus'),
            'url' => env('TELEMETRY_UI_METRICS_URL', 'http://localhost:9090'),
            'prefix' => env('TELEMETRY_UI_METRICS_PREFIX'),
            'tenant' => env('TELEMETRY_UI_METRICS_TENANT'),
            'headers' => [],
            'timeout' => (float) env('TELEMETRY_UI_METRICS_TIMEOUT', 10.0),
        ],

        'traces' => [
            'driver' => 'tempo',
            'url' => env('TELEMETRY_UI_TEMPO_URL', 'http://localhost:3200'),
            'tenant' => env('TELEMETRY_UI_TEMPO_TENANT'),
            'headers' => [],
            'timeout' => (float) env('TELEMETRY_UI_TEMPO_TIMEOUT', 10.0),
        ],

        'logs' => [
            'driver' => 'loki',
            'url' => env('TELEMETRY_UI_LOKI_URL', 'http://localhost:3100'),
            'tenant' => env('TELEMETRY_UI_LOKI_TENANT'),
            'headers' => [],
            'timeout' => (float) env('TELEMETRY_UI_LOKI_TIMEOUT', 10.0),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Schema detection
    |--------------------------------------------------------------------------
    |
    | Pages registered with a detection pattern (e.g. the built-in Statamic
    | page, which lights up when statamic_* metrics exist) probe the metrics
    | backend with one cached query. The result is cached for this many
    | seconds in your default cache store.
    |
    */

    'detection' => [
        'ttl' => (int) env('TELEMETRY_UI_DETECTION_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cards
    |--------------------------------------------------------------------------
    |
    | Cards shown on the dashboard, in order. Packages may append their own
    | at runtime via TelemetryUi::card(MyCard::class); entries listed here
    | come first. Every card is a Livewire component extending
    | Cbox\TelemetryUi\Cards\Card.
    |
    */

    'cards' => [
        RequestsOverview::class,
    ],

];
