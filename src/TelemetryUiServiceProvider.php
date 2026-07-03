<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi;

use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Http\Middleware\Authorize;
use Cbox\TelemetryUi\Support\Annotations;
use Cbox\TelemetryUi\Support\Fleet;
use Cbox\TelemetryUi\Support\SchemaDetector;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
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
        }

        if (! (bool) config('telemetry-ui.enabled', true)) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'telemetry-ui');

        $this->registerRoutes();
        $this->registerGate();
        $this->registerIssuesPage();
        $this->registerLivewireComponents();
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
        Gate::define('viewTelemetryUi', fn (?object $user = null): bool => $this->app->environment('local'));
    }

    private function registerLivewireComponents(): void
    {
        // Runs after every provider has booted so cards contributed by other
        // packages are included. Registration itself is a name => class map.
        $this->app->booted(static function (Application $app): void {
            if (! class_exists(Livewire::class)) {
                return;
            }

            $manager = $app->make(TelemetryUiManager::class);

            foreach ($manager->allCards() as $card) {
                Livewire::component(TelemetryUiManager::componentName($card), $card);
            }

            Livewire::component('telemetry-ui.trace-drawer', TraceDrawer::class);
        });
    }
}
