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
    | Fleet discovery
    |--------------------------------------------------------------------------
    |
    | The sidebar service/environment switcher lists the services and
    | deployment environments discovered from the metrics backend. That
    | label-value lookup is cached for this many seconds.
    |
    */

    'fleet' => [
        'ttl' => (int) env('TELEMETRY_UI_FLEET_TTL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP server
    |--------------------------------------------------------------------------
    |
    | The local stdio server (`php artisan mcp:start telemetry-ui`) always
    | works. To also expose it over HTTP for a hosted agent, flip "web.enabled":
    | laravel/mcp serves the endpoint and — with laravel/passport installed —
    | the OAuth 2.1 authorization server and the Dynamic Client Registration
    | endpoint, so clients can self-register. No custom OAuth code.
    |
    |   composer require laravel/passport      # then run its install
    |   TELEMETRY_UI_MCP_WEB=true
    |
    */

    'mcp' => [
        'web' => [
            'enabled' => (bool) env('TELEMETRY_UI_MCP_WEB', false),
            'path' => env('TELEMETRY_UI_MCP_PATH', 'telemetry-ui/mcp'),
            'middleware' => ['auth:api'],
            'oauth' => (bool) env('TELEMETRY_UI_MCP_OAUTH', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Signal context (correlation)
    |--------------------------------------------------------------------------
    |
    | The thing an app-only monitor can't do: correlate a trace with the host
    | and runtime signals recorded around it, because the same Prometheus
    | scrapes system and process metrics — and node_exporter, mysqld_exporter,
    | … when you run them — right next to the app.
    |
    | Each signal is a PromQL template with a `{scope}` token that expands to
    | the scope's matcher list (service_name, host_name). Signals resolve
    | independently and fail-open, so a signal whose metric is absent (e.g. no
    | node_exporter) is simply skipped — never an error. `window` is the number
    | of seconds padded around a trace so surrounding metric samples land in
    | view.
    |
    | To pull in exporters that don't carry the app's labels, join on the host:
    |   ['label' => 'DB threads', 'group' => 'db', 'unit' => 'number',
    |    'query' => 'mysql_global_status_threads_running{instance="$host"}'],
    | (map host_name -> the exporter's instance label for your setup).
    |
    */

    'context' => [
        'enabled' => (bool) env('TELEMETRY_UI_CONTEXT', true),
        'window' => (int) env('TELEMETRY_UI_CONTEXT_WINDOW', 600),
        // Lookback for each signal's "typical" baseline, so a tile can say
        // "95% (typical 30%)" and flag what was actually different.
        'baseline_window' => (int) env('TELEMETRY_UI_CONTEXT_BASELINE', 21_600),
        'signals' => [
            ['label' => 'Host CPU', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(system_cpu_utilization_ratio{{scope}})'],
            ['label' => 'Load avg', 'group' => 'host', 'unit' => 'number', 'query' => 'max(system_cpu_load_average_ratio{{scope}})'],
            ['label' => 'Host memory', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(system_memory_utilization_ratio{{scope},state="used"})'],
            ['label' => 'Net in', 'group' => 'host', 'unit' => 'bytes/s', 'query' => 'sum(rate(system_network_io_bytes{{scope},direction="receive"}[1m]))'],
            ['label' => 'Process RSS', 'group' => 'runtime', 'unit' => 'bytes', 'query' => 'avg(process_resident_memory_bytes{{scope}})'],
        ],
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

        // Each marker both reads (matched in Loki by "event") and writes (via
        // `php artisan telemetry-ui:annotate <key>`, which emits the event
        // through the telemetry pipeline). Add your own — anything worth a
        // vertical line on every chart.
        'markers' => [
            'deploy' => [
                'event' => 'app.deployment',
                'label' => 'Deploy',
                'color' => '#c084fc',
                'id_label' => 'deployment_id',
                'notes_label' => 'deployment_notes',
            ],
            'incident' => [
                'event' => 'app.incident',
                'label' => 'Incident',
                'color' => '#f87171',
                'id_label' => 'incident_id',
                'notes_label' => 'incident_notes',
            ],
            'scaling' => [
                'event' => 'app.scaling',
                'label' => 'Scaling',
                'color' => '#60a5fa',
                'id_label' => 'scaling_id',
                'notes_label' => 'scaling_notes',
            ],
            'migration' => [
                'event' => 'app.migration',
                'label' => 'Migration',
                'color' => '#34d399',
                'id_label' => 'migration_id',
                'notes_label' => 'migration_notes',
            ],
            'feature' => [
                'event' => 'app.feature_flag',
                'label' => 'Feature flag',
                'color' => '#fbbf24',
                'id_label' => 'feature_id',
                'notes_label' => 'feature_notes',
            ],
            'version' => [
                'event' => 'app.version',
                'label' => 'Version',
                'color' => '#2dd4bf',
                'id_label' => 'version_id',
                'notes_label' => 'version_notes',
            ],
        ],

        // Proactive: `telemetry-ui:scan-versions` (schedule it every few
        // minutes) detects a newly-seen laravel_version in the metrics and
        // auto-emits a "version" annotation for it — so an un-announced deploy
        // still lands on the charts. Stateless: it dedups against the version
        // annotations already in Loki, no local store.
        'auto_version' => [
            'enabled' => (bool) env('TELEMETRY_UI_AUTO_VERSION', false),
            'metric' => env('TELEMETRY_UI_AUTO_VERSION_METRIC', 'system_cpu_utilization_ratio'),
            'marker' => 'version',
            'lookback_days' => (int) env('TELEMETRY_UI_AUTO_VERSION_LOOKBACK', 30),
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
