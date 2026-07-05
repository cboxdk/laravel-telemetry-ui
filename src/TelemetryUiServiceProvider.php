<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi;

use Cbox\TelemetryUi\Analysis\SignalContext;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Http\Middleware\Authorize;
use Cbox\TelemetryUi\Support\Annotations;
use Cbox\TelemetryUi\Support\Fleet;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Cbox\TelemetryUi\Support\ScopeLock;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * Boot cost is deliberately kept to cheap registrations (config merge,
 * container bindings, route/view path maps). Connectors, Livewire
 * components and views are only instantiated when a dashboard URL is hit.
 */
final class TelemetryUiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telemetry-ui.php', 'telemetry-ui');

        $this->app->singleton(ConnectionManager::class, static fn (Application $app): ConnectionManager => new ConnectionManager(
            $app->make('config'),
        ));

        $this->app->singleton(TelemetryUiManager::class, static fn (Application $app): TelemetryUiManager => new TelemetryUiManager(
            $app->make('config'),
        ));

        $this->app->singleton(SchemaDetector::class, static fn (Application $app): SchemaDetector => new SchemaDetector(
            $app->make(ConnectionManager::class),
            $app->make('cache'),
            (int) $app->make('config')->get('telemetry-ui.detection.ttl', 300),
        ));

        $this->app->singleton(Fleet::class, static fn (Application $app): Fleet => new Fleet(
            $app->make(ConnectionManager::class),
            $app->make('cache'),
            (int) $app->make('config')->get('telemetry-ui.fleet.ttl', 60),
        ));

        $this->app->singleton(SignalContext::class, static fn (Application $app): SignalContext => new SignalContext(
            $app->make(ConnectionManager::class),
            $app->make('config'),
            $app->make('cache'),
        ));

        // Request-scoped so a resolved tenancy lock never leaks between
        // requests (users) under a persistent runtime like Octane.
        $this->app->scoped(ScopeLock::class, static fn (Application $app): ScopeLock => new ScopeLock(
            $app->make(TelemetryUiManager::class),
        ));

        $this->app->singleton(Annotations::class, static fn (Application $app): Annotations => new Annotations(
            $app->make(ConnectionManager::class),
            $app->make('cache'),
            $app->make('config'),
            (int) $app->make('config')->get('telemetry-ui.annotations.ttl', 30),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/telemetry-ui.php' => config_path('telemetry-ui.php'),
            ], 'telemetry-ui-config');

            $this->commands([
                Console\CheckCommand::class,
                Console\AnnotateCommand::class,
                Console\ScanVersionsCommand::class,
            ]);
        }

        if (! (bool) config('telemetry-ui.enabled', true)) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'telemetry-ui');

        $this->registerRoutes();
        $this->registerGate();
        $this->registerIssuesPage();
        $this->registerLivewireComponents();
        $this->registerMcpServer();
    }

    /**
     * Register the MCP server. `mcp:start telemetry-ui` serves it over stdio;
     * flipping telemetry-ui.mcp.web.enabled also exposes it over HTTP with the
     * OAuth 2.1 + Dynamic Client Registration flow laravel/mcp provides on top
     * of laravel/passport — no custom OAuth here.
     */
    private function registerMcpServer(): void
    {
        if (! class_exists(\Laravel\Mcp\Facades\Mcp::class)) {
            return;
        }

        // stdio registration writes to the transport, so skip it under unit
        // tests (which drive tools directly via Server::tool()).
        if (! $this->app->runningUnitTests()) {
            \Laravel\Mcp\Facades\Mcp::local('telemetry-ui', Mcp\Servers\TelemetryServer::class);
        }

        if (! (bool) config('telemetry-ui.mcp.web.enabled', false)) {
            return;
        }

        \Laravel\Mcp\Facades\Mcp::web(
            (string) config('telemetry-ui.mcp.web.path', 'telemetry-ui/mcp'),
            Mcp\Servers\TelemetryServer::class,
        )->middleware((array) config('telemetry-ui.mcp.web.middleware', ['auth:api']));

        if ((bool) config('telemetry-ui.mcp.web.oauth', true)) {
            // Fail loud rather than expose an unprotected authorization flow:
            // the OAuth 2.1 + DCR endpoints laravel/mcp registers are backed by
            // Passport, so a deploy that enables web MCP with oauth but forgot
            // to install/run Passport must not boot into a half-configured auth
            // server. Set telemetry-ui.mcp.web.oauth=false only if you front the
            // endpoint with your own auth middleware.
            if (! class_exists(Passport::class)) {
                throw new \RuntimeException(
                    'telemetry-ui: MCP web transport has OAuth enabled but laravel/passport is not installed. '
                    .'Run `composer require laravel/passport` and `php artisan passport:install`, '
                    .'or set telemetry-ui.mcp.web.oauth=false to secure the endpoint yourself.'
                );
            }

            \Laravel\Mcp\Facades\Mcp::oauthRoutes();
        }
    }

    private function registerIssuesPage(): void
    {
        // Config-gated (not metric-detected): only appears when an issue
        // tracker connection is set. Registration is data-only.
        if (config('telemetry-ui.connections.issues.driver') === null) {
            return;
        }

        $manager = $this->app->make(TelemetryUiManager::class);
        $manager->page('issues', 'Issues', group: null);
        $manager->card(Cards\Builtin\IssuesList::class, page: 'issues');
    }

    private function registerRoutes(): void
    {
        $middleware = [...(array) config('telemetry-ui.middleware', ['web']), Authorize::class];

        $throttle = config('telemetry-ui.throttle');
        if (is_string($throttle) && $throttle !== '') {
            $middleware[] = 'throttle:'.$throttle;
        }

        Route::group([
            'domain' => config('telemetry-ui.domain'),
            'prefix' => config('telemetry-ui.path', 'telemetry-ui'),
            'middleware' => $middleware,
            'as' => '',
        ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/web.php'));
    }

    private function registerGate(): void
    {
        // Deny-by-default outside local; apps open access by redefining the
        // gate (app providers boot after this one, so their definition wins).
        // The second argument is the page slug being accessed (null for
        // Livewire updates and cross-cutting checks), so an app can restrict
        // individual pages — e.g. hide Logs from non-admins — without closing
        // the whole dashboard. The default ignores it.
        Gate::define('viewTelemetryUi', fn (?object $user = null, ?string $page = null): bool => $this->app->environment('local'));

        // Write actions (creating tracker issues from the UI) require a
        // separate ability so a read-only viewer can't file tickets. It falls
        // back to the view gate unless the app defines something stricter
        // (app definitions boot later and win).
        Gate::define('manageTelemetryUi', static fn (?object $user = null): bool => Gate::allows('viewTelemetryUi'));
    }

    private function registerLivewireComponents(): void
    {
        // Runs after every provider has booted so cards contributed by other
        // packages are included. Registration itself is a name => class map.
        $this->app->booted(static function (Application $app): void {
            if (! class_exists(Livewire::class)) {
                return;
            }

            // Re-run the gate on every Livewire update, not just the initial
            // page load: card/drawer actions (e.g. creating a ticket) POST to
            // /livewire/update, which otherwise only carries Livewire's own
            // persistent middleware — the dashboard gate would be skipped.
            Livewire::addPersistentMiddleware(Authorize::class);

            $manager = $app->make(TelemetryUiManager::class);

            foreach ($manager->allCards() as $card) {
                Livewire::component(TelemetryUiManager::componentName($card), $card);
            }

            Livewire::component('telemetry-ui.trace-drawer', TraceDrawer::class);
        });
    }
}
