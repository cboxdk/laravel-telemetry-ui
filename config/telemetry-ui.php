<?php

declare(strict_types=1);
use Cbox\TelemetryUi\Cards\Builtin\DeploysTimeline;
use Cbox\TelemetryUi\Cards\Builtin\ExceptionsOverview;
use Cbox\TelemetryUi\Cards\Builtin\JobsOverview;
use Cbox\TelemetryUi\Cards\Builtin\RequestDuration;
use Cbox\TelemetryUi\Cards\Builtin\RequestsActivity;

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
    | Throttle
    |--------------------------------------------------------------------------
    |
    | Rate limit for the dashboard routes, as "maxAttempts,decayMinutes".
    | The dashboard fans out to the metrics/traces/logs backends on every
    | render and auto-refresh tick, so this caps how hard a single client can
    | drive them. Set to null to disable.
    |
    */

    'throttle' => env('TELEMETRY_UI_THROTTLE', '120,1'),

    /*
    |--------------------------------------------------------------------------
    | Query cache & retries
    |--------------------------------------------------------------------------
    |
    | Every card issues live backend queries on each render and auto-refresh
    | tick. "cache.ttl" caches decoded GET responses (plain arrays, safe on
    | any store) for that many seconds so a busy dashboard with many cards and
    | concurrent viewers does not hammer Prometheus/Tempo/Loki. Keep it short
    | (a few seconds) so data stays fresh; 0 disables. A connection may set its
    | own "cache" to override. "retries" retries transient connection blips.
    |
    */

    'cache' => [
        'ttl' => (int) env('TELEMETRY_UI_CACHE_TTL', 5),
    ],

    'retries' => (int) env('TELEMETRY_UI_RETRIES', 2),

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
    | Auth: set "token" for a Bearer token (e.g. a Grafana service account,
    | when querying through Grafana's datasource proxy) or "basic_auth" as
    | "user:pass"; both are turned into an Authorization header. Add any other
    | headers under "headers".
    |
    | Grafana datasource proxy recipe (no direct backend access needed):
    |   URL  = https://grafana.example.com/api/datasources/proxy/uid/<uid>
    |   token = <grafana service-account token, Viewer role>
    | The Loki proxy expects the driver's own "/loki/..." path on top of the
    | proxy base, which this package already sends.
    |
    */

    'connections' => [

        'metrics' => [
            'driver' => env('TELEMETRY_UI_METRICS_DRIVER', 'prometheus'),
            'url' => env('TELEMETRY_UI_METRICS_URL', 'http://localhost:9090'),
            'prefix' => env('TELEMETRY_UI_METRICS_PREFIX'),
            'tenant' => env('TELEMETRY_UI_METRICS_TENANT'),
            'token' => env('TELEMETRY_UI_METRICS_TOKEN', env('TELEMETRY_UI_TOKEN')),
            'basic_auth' => env('TELEMETRY_UI_METRICS_BASIC_AUTH'),
            'headers' => [],
            'timeout' => (float) env('TELEMETRY_UI_METRICS_TIMEOUT', 10.0),
        ],

        'traces' => [
            'driver' => 'tempo',
            'url' => env('TELEMETRY_UI_TEMPO_URL', 'http://localhost:3200'),
            'tenant' => env('TELEMETRY_UI_TEMPO_TENANT'),
            'token' => env('TELEMETRY_UI_TEMPO_TOKEN', env('TELEMETRY_UI_TOKEN')),
            'basic_auth' => env('TELEMETRY_UI_TEMPO_BASIC_AUTH'),
            'headers' => [],
            'timeout' => (float) env('TELEMETRY_UI_TEMPO_TIMEOUT', 10.0),
        ],

        'logs' => [
            'driver' => 'loki',
            'url' => env('TELEMETRY_UI_LOKI_URL', 'http://localhost:3100'),
            'tenant' => env('TELEMETRY_UI_LOKI_TENANT'),
            'token' => env('TELEMETRY_UI_LOKI_TOKEN', env('TELEMETRY_UI_TOKEN')),
            'basic_auth' => env('TELEMETRY_UI_LOKI_BASIC_AUTH'),
            'headers' => [],
            'timeout' => (float) env('TELEMETRY_UI_LOKI_TIMEOUT', 10.0),
        ],

        // Optional issue tracker (GitHub, Sentry, Linear, …). When a driver is
        // set, an "Issues" page appears in the sidebar. Disabled by default.
        'issues' => [
            'driver' => env('TELEMETRY_UI_ISSUES_DRIVER'),
            'repo' => env('TELEMETRY_UI_GITHUB_REPO'),
            'url' => env('TELEMETRY_UI_ISSUES_URL'),
            'token' => env('TELEMETRY_UI_ISSUES_TOKEN'),
            'timeout' => (float) env('TELEMETRY_UI_ISSUES_TIMEOUT', 10.0),
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
    | Annotations
    |--------------------------------------------------------------------------
    |
    | Point-in-time markers drawn as vertical lines on every chart, the way
    | Grafana annotations map regressions to deploys. cboxdk/laravel-telemetry
    | emits `app.deployment` events (via `php artisan telemetry:deploy`) into
    | the logs backend; each marker below is matched there by its event name,
    | reading id/notes from the event's structured metadata.
    |
    */

    'annotations' => [
        'enabled' => (bool) env('TELEMETRY_UI_ANNOTATIONS', true),
        'ttl' => (int) env('TELEMETRY_UI_ANNOTATIONS_TTL', 30),
        'markers' => [
            'deploy' => [
                'event' => 'app.deployment',
                'label' => 'Deploy',
                'color' => '#c084fc',
                'id_label' => 'deployment_id',
                'notes_label' => 'deployment_notes',
            ],
        ],
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
        RequestsActivity::class,
        RequestDuration::class,
        ExceptionsOverview::class,
        JobsOverview::class,
        DeploysTimeline::class,
    ],

];
